<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use ArchivedFile;
use File;
use Language;
use LocalFile;
use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Html\Html;
use MediaWiki\Mail\IEmailer;
use MediaWiki\MainConfigNames;
use MessageLocalizer;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class MediaModerationEmailer {

	public const CONSTRUCTOR_OPTIONS = [
		'MediaModerationRecipientList',
		'MediaModerationFrom',
		MainConfigNames::CanonicalServer,
	];

	private ServiceOptions $options;
	private IEmailer $emailer;
	private MediaModerationFileLookup $mediaModerationFileLookup;
	private MessageLocalizer $messageLocalizer;
	private Language $language;
	private LoggerInterface $logger;

	public function __construct(
		ServiceOptions $options,
		IEmailer $emailer,
		MediaModerationFileLookup $mediaModerationFileLookup,
		MessageLocalizer $messageLocalizer,
		Language $language,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->emailer = $emailer;
		$this->mediaModerationFileLookup = $mediaModerationFileLookup;
		$this->messageLocalizer = $messageLocalizer;
		$this->language = $language;
		$this->logger = $logger;
	}

	/**
	 * Send an email to those listed in wgMediaModerationRecipientList about files with the given $sha1.
	 *
	 * Do not call this unless the SHA-1 has been determined to be a positive match by PhotoDNA.
	 *
	 * @param string $sha1 The SHA-1 of files to send an email.
	 * @param ?string $minimumTimestamp Optional. If provided, limits the files that are sent in the email
	 *   to those which are uploaded after this date.
	 * @return StatusValue
	 */
	public function sendEmailForSha1( string $sha1, ?string $minimumTimestamp = null ) {
		$to = array_map( static function ( $address ) {
			return new MailAddress( $address );
		}, $this->options->get( 'MediaModerationRecipientList' ) );

		$emailerStatus = $this->emailer->send(
			$to,
			new MailAddress( $this->options->get( 'MediaModerationFrom' ) ),
			$this->getEmailSubject( $sha1 ),
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
	 * Returns the subject line for the email sent by ::sendEmailForSha1.
	 *
	 * The subject line includes the SHA-1 and the current date and time to avoid duplications.
	 * Just using the date and time is not unique enough in the case that resendMatchEmails.php is run,
	 * as multiple emails with the same send time could be sent (as the seconds are not included)
	 *
	 * @param string $sha1
	 * @return string
	 */
	protected function getEmailSubject( string $sha1 ): string {
		return $this->messageLocalizer->msg( 'mediamoderation-email-subject' )
			->params( $sha1 )
			->dateTimeParams( ConvertibleTimestamp::now() )
			->escaped();
	}

	/**
	 * Generates a list of File and ArchivedFile objects grouped by their file name (result of ::getName).
	 *
	 * @param string $sha1
	 * @param ?string $minimumTimestamp If not null, then only include File/ArchivedFile objects for this SHA-1 which
	 *   have a timestamp greater than or equal to this value.
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
	 * @param ?string $minimumTimestamp See ::sendEmailForSha1
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
		return $this->getEmailBodyIntroductionText( $fileObjectsCount, true ) . $returnHtml .
			$this->getEmailBodyFooterText( $missingTimestamps );
	}

	/**
	 * Returns the plaintext version of the email sent by ::sendEmailForSha1
	 *
	 * @param string $sha1
	 * @param ?string $minimumTimestamp See ::sendEmailForSha1
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
		return $this->getEmailBodyIntroductionText( $fileObjectsCount, false ) . $returnText .
			$this->getEmailBodyFooterText( $missingTimestamps );
	}

	/**
	 * Returns text to be added to the start of the email body for both the plaintext and HTML version.
	 *
	 * @param int $fileRevisionsCount The number of File/ArchivedFile objects processed which have ::getTimestamp
	 *   return any other value than false.
	 * @param bool $useHtml If true, then the HTML version of the email introduction is returned.
	 * @return string
	 */
	protected function getEmailBodyIntroductionText( int $fileRevisionsCount, bool $useHtml ): string {
		// Add the introduction text to the start of the return text
		$introductionMessage = $this->messageLocalizer->msg( 'mediamoderation-email-body-intro' )
			->numParams( $fileRevisionsCount );
		if ( $useHtml ) {
			$introductionMessage->rawParams( Html::element(
				'a',
				[ 'href' => $this->options->get( MainConfigNames::CanonicalServer ) ],
				$this->options->get( MainConfigNames::CanonicalServer )
			) );
		} else {
			$introductionMessage->params( $this->options->get( MainConfigNames::CanonicalServer ) );
		}
		return $introductionMessage->escaped() . "\n";
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
