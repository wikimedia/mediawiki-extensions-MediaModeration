<?php

namespace MediaWiki\Extension\MediaModeration\Maintenance;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationEmailer;
use MediaWiki\Maintenance\Maintenance;
use MessageLocalizer;
use StatusValue;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Re-sends emails for SHA-1 values determined to be a match for files from a given timestamp.
 * Designed for use when emailing was broken and the emails need to be re-sent from the timestamp
 * when emailing stopped working.
 */
class ResendMatchEmails extends Maintenance {

	private MediaModerationEmailer $mediaModerationEmailer;
	private MediaModerationDatabaseLookup $mediaModerationDatabaseLookup;
	private MessageLocalizer $messageLocalizer;

	private string $scannedSince;
	private ?string $uploadedSince;

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'MediaModeration' );
		$this->addDescription(
			'Maintenance script to re-send emails for SHA-1 values marked as a match in the ' .
			'mediamoderation_scan table.'
		);

		$this->addArg(
			'scanned-since',
			'Only re-send emails for SHA-1 values that have been scanned since a given date.',
		);
		$this->addOption(
			'uploaded-since',
			'Only include files in the email that were uploaded to the wiki after this timestamp. Default' .
			'is to not filter by upload timestamp.',
			false,
			true
		);
		$this->addOption(
			'sleep',
			'How long to sleep (in seconds) after sending an email. Default: 1',
			false,
			true
		);
		$this->addOption(
			'verbose',
			'Enables verbose mode which prints out information about the emails being sent.'
		);
	}

	public function execute() {
		$this->initServices();
		$this->parseTimestamps();

		$previousBatchLastSha1Value = '';
		do {
			$batch = $this->getSelectQueryBuilder( $previousBatchLastSha1Value )
				->caller( __METHOD__ )
				->fetchFieldValues();
			foreach ( $batch as $sha1 ) {
				// Send an email for this batch.
				$emailerStatus = $this->mediaModerationEmailer->sendEmailForSha1( $sha1, $this->uploadedSince );
				$this->outputInformationBasedOnStatus( $sha1, $emailerStatus );
				// Wait for --sleep seconds to avoid spamming the email address getting these reports.
				sleep( intval( $this->getOption( 'sleep', 1 ) ) );
				// Update $previousBatchLastSha1Value to the current $sha1. Once this loop
				// completes, the value will be the last SHA-1 in this batch.
				$previousBatchLastSha1Value = $sha1;
			}
		} while ( count( $batch ) );
	}

	/**
	 * Outputs verbose information or errors based on the provided StatusValue.
	 *
	 * @param string $sha1
	 * @param StatusValue $emailerStatus
	 * @return void
	 */
	protected function outputInformationBasedOnStatus( string $sha1, StatusValue $emailerStatus ) {
		if ( $emailerStatus->isGood() ) {
			if ( $this->hasOption( 'verbose' ) ) {
				$this->output( "Sent email for SHA-1 $sha1.\n" );
			}
		} else {
			$this->error( "Email for SHA-1 $sha1 failed to send.\n" );
			$errorOutput = '';
			foreach ( $emailerStatus->getMessages() as $message ) {
				$errorOutput .= '* ' . $this->messageLocalizer->msg( $message ) . "\n";
			}
			$this->error( $errorOutput );
		}
	}

	/**
	 * Gets a SelectQueryBuilder that can be used to produce a batch of SHA-1 values to be passed to
	 * MediaModerationEmailer::sendEmailForSha1.
	 *
	 * @param string $previousBatchLastSha1Value The last SHA-1 value from the previous batch, or an empty string
	 *   if processing the first batch.
	 * @return SelectQueryBuilder
	 */
	protected function getSelectQueryBuilder( string $previousBatchLastSha1Value ): SelectQueryBuilder {
		// Get a replica DB connection.
		$dbr = $this->mediaModerationDatabaseLookup->getDb( IDBAccessObject::READ_NORMAL );
		$selectQueryBuilder = $dbr->newSelectQueryBuilder()
			->select( 'mms_sha1' )
			->from( 'mediamoderation_scan' )
			->where( [
				$dbr->expr( 'mms_last_checked', '>=', (int)$this->scannedSince ),
				'mms_is_match' => (int)MediaModerationDatabaseLookup::POSITIVE_MATCH_STATUS,
			] )
			->orderBy( 'mms_sha1', SelectQueryBuilder::SORT_ASC )
			->limit( 200 );
		if ( $previousBatchLastSha1Value ) {
			$selectQueryBuilder->andWhere( [
				$dbr->expr( 'mms_sha1', '>', $previousBatchLastSha1Value )
			] );
		}
		return $selectQueryBuilder;
	}

	protected function initServices(): void {
		$services = $this->getServiceContainer();
		$this->mediaModerationDatabaseLookup = $services->get( 'MediaModerationDatabaseLookup' );
		$this->mediaModerationEmailer = $services->get( 'MediaModerationEmailer' );
		$this->messageLocalizer = RequestContext::getMain();
	}

	/**
	 * Parse the 'last-checked' timestamp provided via the command line,
	 * and cause a fatal error if it cannot be parsed.
	 *
	 * @return void
	 */
	protected function parseTimestamps(): void {
		$scannedSince = $this->getArg( 'scanned-since' );
		if ( !is_string( $scannedSince ) ) {
			$this->fatalError( 'The scanned-since argument must be a string.' );
		}
		if ( strlen( $scannedSince ) === 8 && $scannedSince === strval( intval( $scannedSince ) ) ) {
			// The 'scanned-since' argument is likely to be in the form YYYYMMDD because:
			// * The length of the argument is 8 (which is the length of a YYYYMMDD format)
			// * The intval of the 'scanned-since' parameter can be converted to an integer
			//    and from a string without any changes in value (thus it must be an integer
			//    in string form).
			$this->scannedSince = $scannedSince;
		} elseif ( ConvertibleTimestamp::convert( TS_MW, $scannedSince ) ) {
			// If the 'scanned-since' argument is recognised as a timestamp by ConvertibleTimestamp::convert,
			// then get the date part and discard the time part.
			$this->scannedSince = $this->mediaModerationDatabaseLookup->getDateFromTimestamp( $scannedSince );
		} else {
			// The 'scanned-since' argument could not be parsed, so raise an error
			$this->fatalError(
				'The scanned-since argument passed to this script could not be parsed. This can take a ' .
				'timestamp in string form, or a date in YYYYMMDD format.'
			);
		}
		// Uploaded since takes any supported timestamp by ConvertibleTimestamp
		$uploadedSince = $this->getOption( 'uploaded-since' );
		if ( $uploadedSince !== null ) {
			$uploadedSince = ConvertibleTimestamp::convert( TS_MW, $uploadedSince );
			if ( !$uploadedSince ) {
				$this->fatalError( 'The uploaded-since timestamp could not be parsed as a valid timestamp' );
			}
		}
		$this->uploadedSince = $uploadedSince;
	}
}

// @codeCoverageIgnoreStart
$maintClass = ResendMatchEmails::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
