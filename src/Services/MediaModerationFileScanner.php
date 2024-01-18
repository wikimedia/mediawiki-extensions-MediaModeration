<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Extension\MediaModeration\PhotoDNA\IMediaModerationPhotoDNAServiceProvider;
use MediaWiki\Extension\MediaModeration\PhotoDNA\Response;
use MediaWiki\Language\RawMessage;
use MediaWiki\Status\StatusFormatter;
use Psr\Log\LoggerInterface;
use StatusValue;

/**
 * Scans SHA-1 values in batches to reduce the effect that slow requests to
 * PhotoDNA have on the overall speed of scanning.
 */
class MediaModerationFileScanner {

	private MediaModerationDatabaseManager $mediaModerationDatabaseManager;
	private MediaModerationDatabaseLookup $mediaModerationDatabaseLookup;
	private MediaModerationFileLookup $mediaModerationFileLookup;
	private MediaModerationFileProcessor $mediaModerationFileProcessor;
	private IMediaModerationPhotoDNAServiceProvider $mediaModerationPhotoDNAServiceProvider;
	private StatsdDataFactoryInterface $perDbNameStatsdDataFactory;
	private StatusFormatter $statusFormatter;
	private LoggerInterface $logger;

	public function __construct(
		MediaModerationDatabaseLookup $mediaModerationDatabaseLookup,
		MediaModerationDatabaseManager $mediaModerationDatabaseManager,
		MediaModerationFileLookup $mediaModerationFileLookup,
		MediaModerationFileProcessor $mediaModerationFileProcessor,
		IMediaModerationPhotoDNAServiceProvider $mediaModerationPhotoDNAServiceProvider,
		StatusFormatter $statusFormatter,
		StatsdDataFactoryInterface $perDbNameStatsdDataFactory,
		LoggerInterface $logger
	) {
		$this->mediaModerationDatabaseLookup = $mediaModerationDatabaseLookup;
		$this->mediaModerationDatabaseManager = $mediaModerationDatabaseManager;
		$this->mediaModerationFileLookup = $mediaModerationFileLookup;
		$this->mediaModerationFileProcessor = $mediaModerationFileProcessor;
		$this->mediaModerationPhotoDNAServiceProvider = $mediaModerationPhotoDNAServiceProvider;
		$this->statusFormatter = $statusFormatter;
		$this->perDbNameStatsdDataFactory = $perDbNameStatsdDataFactory;
		$this->logger = $logger;
	}

	/**
	 * Scans the files that have the given SHA-1
	 *
	 * @param string $sha1
	 * @return StatusValue
	 */
	public function scanSha1( string $sha1 ): StatusValue {
		$returnStatus = new StatusValue();
		// Until a match is got from PhotoDNA, the return status should be not okay as the operation has not completed.
		$returnStatus->setOK( false );
		// Get the current scan status from the DB, so that we keep the current value if
		// nothing matches but still update the last checked value.
		$oldMatchStatus = $this->mediaModerationDatabaseLookup->getMatchStatusForSha1( $sha1 );
		$newMatchStatus = null;
		foreach ( $this->mediaModerationFileLookup->getFileObjectsForSha1( $sha1 ) as $file ) {
			if ( !$this->mediaModerationFileProcessor->canScanFile( $file ) ) {
				// If this $file cannot be scanned, then try the next file with this SHA-1
				// and if in verbose mode output to the console about this.
				$this->perDbNameStatsdDataFactory->increment( 'MediaModeration.FileScanner.CanScanFileReturnedFalse' );
				$returnStatus->fatal( new RawMessage(
					'The file $1 cannot be scanned.',
					[ $file->getName() ]
				) );
				continue;
			}
			// Run the check using the PhotoDNA API.
			$checkResult = $this->mediaModerationPhotoDNAServiceProvider->check( $file );
			/** @var Response|null $response */
			$response = $checkResult->getValue();
			if ( $response === null || $response->getStatusCode() !== Response::STATUS_OK ) {
				// Assume something is wrong with the thumbnail or source file if the request fails,
				// and just try a new $file with this SHA-1. Add the information about the
				// failure to the return status for tracking and logging.
				$returnStatus->merge( $checkResult );
				continue;
			}
			$newMatchStatus = $response->isMatch();
			// Stop processing this SHA-1 as we have a result.
			break;
		}
		// Update the match status, even if none of the $file objects could be scanned.
		// If no scanning was successful, then the status will remain
		$this->mediaModerationDatabaseManager->updateMatchStatusForSha1( $sha1, $newMatchStatus ?? $oldMatchStatus );
		// TODO: Send an email if $newMatchStatus is true (T351407).
		if ( $newMatchStatus !== null ) {
			$returnStatus->setResult( true, $newMatchStatus );
		}
		if ( !$returnStatus->isOK() ) {
			// Create a info if the SHA-1 could not be scanned.
			$this->logger->info(
				'Unable to scan SHA-1 {sha1}. MediaModerationFileScanner::scanSha1 returned this: {return-message}',
				[
					'sha1' => $sha1,
					'return-message' => $this->statusFormatter->getMessage( $returnStatus, [ 'lang' => 'en' ] ),
				]
			);
		} elseif ( !$returnStatus->isGood() ) {
			// Create a debug if the SHA-1 scanning succeeded with warnings.
			$this->logger->debug(
				'Scan of SHA-1 {sha1} succeeded with warnings. MediaModerationFileScanner::scanSha1 ' .
				'returned this: {return-message}',
				[
					'sha1' => $sha1,
					'return-message' => $this->statusFormatter->getMessage( $returnStatus, [ 'lang' => 'en' ] ),
				]
			);
		}
		return $returnStatus;
	}
}
