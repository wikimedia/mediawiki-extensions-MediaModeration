<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use ArchivedFile;
use File;
use IDBAccessObject;
use Language;
use LocalFile;
use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Html\Html;
use MediaWiki\Language\RawMessage;
use MediaWiki\Mail\IEmailer;
use MediaWiki\MainConfigNames;
use MessageLocalizer;
use Psr\Log\LoggerInterface;
use StatusValue;

class MediaModerationEmailer {

	public const CONSTRUCTOR_OPTIONS = [
		'MediaModerationRecipientList',
		'MediaModerationFrom',
		MainConfigNames::Sitename,
	];

	private ServiceOptions $options;
	private IEmailer $emailer;
	private MediaModerationDatabaseLookup $mediaModerationDatabaseLookup;
	private MediaModerationFileLookup $mediaModerationFileLookup;
	private MessageLocalizer $messageLocalizer;
	private Language $language;
	private LoggerInterface $logger;

	public function __construct(
		ServiceOptions $options,
		IEmailer $emailer,
		MediaModerationDatabaseLookup $mediaModerationDatabaseLookup,
		MediaModerationFileLookup $mediaModerationFileLookup,
		MessageLocalizer $messageLocalizer,
		Language $language,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->emailer = $emailer;
		$this->mediaModerationDatabaseLookup = $mediaModerationDatabaseLookup;
		$this->mediaModerationFileLookup = $mediaModerationFileLookup;
		$this->messageLocalizer = $messageLocalizer;
		$this->language = $language;
		$this->logger = $logger;
	}

	/**
	 * Send an email to those listed in wgMediaModerationRecipientList about files with the given $sha1.
	 *
	 * @param string $sha1 The SHA-1 of files to send an email.
	 * @param ?string $minimumTimestamp Optional. If provided, limits the files that are sent in the email
	 *   to those which are uploaded after this date.
	 * @return StatusValue
	 */
	public function sendEmailForSha1( string $sha1, ?string $minimumTimestamp = null ) {
		// First double check that the SHA-1 is marked as a match using the primary DB as the mms_is_match
		// value probably just was updated.
		if ( !$this->mediaModerationDatabaseLookup->getMatchStatusForSha1( $sha1, IDBAccessObject::READ_LATEST ) ) {
			// If the SHA-1 isn't marked as a match, log a warning and return without sending an email.
			$this->logger->error(
				'Attempted to send email for SHA-1 {sha1} that was not a match.',
				[ 'sha1' => $sha1 ]
			);
			return StatusValue::newFatal( new RawMessage(
				'Attempted to send email for SHA-1 $1 that was not a match.',
				[ $sha1 ]
			) );
		}

		$to = array_map( static function ( $address ) {
			return new MailAddress( $address );
		}, $this->options->get( 'MediaModerationRecipientList' ) );

		$emailerStatus = $this->emailer->send(
			$to,
			new MailAddress( $this->options->get( 'MediaModerationFrom' ) ),
			$this->messageLocalizer->msg( 'mediamoderation-email-subject' )->escaped(),
			$this->getEmailBodyPlaintext( $sha1, $minimumTimestamp ),
			$this->getEmailBodyHtml( $sha1, $minimumTimestamp )
		);
		if ( !$emailerStatus->isGood() ) {
			// Something went wrong and the email did not send properly. Log this as a critical error.
			$this->logger->critical(
				'Email indicating SHA-1 match failed to send. SHA-1: {sha1}',
				[ 'sha1' => $sha1, 'status' => $emailerStatus ]
			);
		}
		return $emailerStatus;
	}

	/**
	 * Generates a list of File and ArchivedFile objects grouped by their file name (result of ::getName).
	 *
	 * @param string $sha1
	 * @param ?string $minimumTimestamp
	 * @return LocalFile[][]|ArchivedFile[][]
	 */
	protected function getFileObjectsGroupedByFileName( string $sha1, ?string $minimumTimestamp ): array {
		$fileObjectsGroupedByFilename = [];
		foreach ( $this->mediaModerationFileLookup->getFileObjectsForSha1( $sha1, 50 ) as $file ) {
			// If we are filtering by timestamp, then remove the File/ArchivedFile if the ::getTimestamp method returns
			// a truthy value and is less than $minimumTimestamp. Falsey values of ::getTimestamp are handled
			// elsewhere.
			if ( $minimumTimestamp !== null && $file->getTimestamp() && $file->getTimestamp() < $minimumTimestamp ) {
				continue;
			}
			// Add the object to the return array grouped by filename.
			if ( !array_key_exists( $file->getName(), $fileObjectsGroupedByFilename ) ) {
				$fileObjectsGroupedByFilename[$file->getName()] = [];
			}
			$fileObjectsGroupedByFilename[$file->getName()][] = $file;
		}
		return $fileObjectsGroupedByFilename;
	}

	/**
	 * Returns the HTML version of the email sent by ::sendEmailForSha1
	 *
	 * @param string $sha1
	 * @param ?string $minimumTimestamp
	 * @return string HTML
	 */
	protected function getEmailBodyHtml( string $sha1, ?string $minimumTimestamp ): string {
		// Keeps a track of whether any File/ArchivedFile objects had ::getTimestamp return false,
		// and if so what the filename was.
		$missingTimestamps = [];
		$returnHtml = '';
		$fileObjectsCount = 0;
		foreach ( $this->getFileObjectsGroupedByFileName( $sha1, $minimumTimestamp ) as $fileName => $files ) {
			// Generate a comma-seperator list of the upload timestamps for the matching file versions, with
			// the URL to the image as a clickable link if it can be accessed publicly.
			$uploadTimestampsForFile = [];
			foreach ( $files as $file ) {
				$fileTimestamp = $file->getTimestamp();
				if ( !$fileTimestamp ) {
					$missingTimestamps[] = $fileName;
					continue;
				}
				$fileObjectsCount++;
				// Convert the timestamp out of the computer readable format into a human readable format.
				$timestampInReadableFormat = htmlspecialchars(
					$this->language->timeanddate( $file->getTimestamp(), false, false )
				);
				if ( $file instanceof File && $file->getFullUrl() ) {
					// If we have a public URL for the image, then use it as the link target for the
					// text of the human readable timestamp.
					$uploadTimestampsForFile[] = Html::rawElement(
						'a',
						[ 'href' => $file->getFullUrl() ],
						$timestampInReadableFormat
					);
				} else {
					// If no public URL can be found, then just add the timestamp.
					$uploadTimestampsForFile[] = $timestampInReadableFormat;
				}
			}
			if ( !count( $uploadTimestampsForFile ) ) {
				// Don't display an empty list which can occur when all matching versions had no upload timestamp.
				continue;
			}
			// Combine the timestamps into a single line for the email html.
			$returnHtml .= $this->messageLocalizer->msg( 'mediamoderation-email-body-file-line' )
				->params( $fileName )
				->rawParams( $this->language->listToText( $uploadTimestampsForFile ) )
				->parse() . "\n";
		}
		return $this->getEmailBodyIntroductionText( $fileObjectsCount ) . $returnHtml .
			$this->getEmailBodyFooterText( $missingTimestamps );
	}

	/**
	 * Returns the plaintext version of the email sent by ::sendEmailForSha1
	 *
	 * @param string $sha1
	 * @param ?string $minimumTimestamp
	 * @return string
	 */
	protected function getEmailBodyPlaintext( string $sha1, ?string $minimumTimestamp ): string {
		$missingTimestamps = [];
		$returnText = '';
		$fileObjectsCount = 0;
		foreach ( $this->getFileObjectsGroupedByFileName( $sha1, $minimumTimestamp ) as $fileName => $files ) {
			// Generate a comma seperated list of the upload timestamps for file versions that matched. If there is a
			// publicly accessible URL to the image available, then add after the timestamp.
			$uploadTimestampsForFile = [];
			foreach ( $files as $file ) {
				$fileTimestamp = $file->getTimestamp();
				if ( !$fileTimestamp ) {
					$missingTimestamps[] = $fileName;
					continue;
				}
				$fileObjectsCount++;
				// Convert the timestamp out of the computer readable format into a human readable format.
				$timestampInReadableFormat = $this->language->timeanddate( $file->getTimestamp(), false, false );
				if ( $file instanceof File && $file->getFullUrl() ) {
					// If a public URL is defined, then add this after the upload timestamp for the file version.
					$uploadTimestampsForFile[] = $this->messageLocalizer
						->msg( 'mediamoderation-email-body-file-revision-plaintext-url' )
						->params( $timestampInReadableFormat, $file->getFullUrl() )
						->escaped();
				} else {
					// If no public URL can be found, then just add the timestamp.
					$uploadTimestampsForFile[] = $timestampInReadableFormat;
				}
			}
			if ( !count( $uploadTimestampsForFile ) ) {
				// Don't display an empty list which can occur when all matching versions had no upload timestamp.
				continue;
			}
			// Combine the upload timestamps into a comma seperated list.
			$returnText .= $this->messageLocalizer->msg(
				'mediamoderation-email-body-file-line',
				$fileName,
				$this->language->listToText( $uploadTimestampsForFile )
			)->escaped() . "\n";
		}
		return $this->getEmailBodyIntroductionText( $fileObjectsCount ) . $returnText .
			$this->getEmailBodyFooterText( $missingTimestamps );
	}

	/**
	 * Returns text to be added to the start of the email body for both the plaintext and HTML version.
	 *
	 * @param int $fileRevisionsCount The number of File/ArchivedFile objects processed which have ::getTimestamp
	 *   return any other value than false.
	 * @return string
	 */
	protected function getEmailBodyIntroductionText( int $fileRevisionsCount ): string {
		// Add the introduction text to the start of the return text
		return $this->messageLocalizer->msg( 'mediamoderation-email-body-intro' )
			->numParams( $fileRevisionsCount )
			->params( $this->options->get( MainConfigNames::Sitename ) )
			->escaped() . "\n";
	}

	/**
	 * Returns text to be added to the end of the email body for both the plaintext and HTML version.
	 *
	 * @param array $missingTimestamps The filenames which had File objects with ::getTimestamp returning false.
	 * @return string
	 */
	protected function getEmailBodyFooterText( array $missingTimestamps ): string {
		if ( count( $missingTimestamps ) ) {
			// If we have any timestamps that were false, then indicate which filenames these were for at the bottom
			// of the email.
			return $this->messageLocalizer
				->msg( 'mediamoderation-email-body-files-missing-timestamp' )
				->params( $this->language->listToText( array_unique( $missingTimestamps ) ) )
				->escaped() . "\n";
		}
		return '';
	}
}
