<?php

namespace MediaWiki\Extension\MediaModeration\Maintenance;

use IDBAccessObject;
use JobQueueError;
use JobQueueGroup;
use JobSpecification;
use Maintenance;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileScanner;
use MediaWiki\Status\StatusFormatter;
use RequestContext;
use StatusValue;
use Wikimedia\Rdbms\LBFactory;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Scans files referenced by their SHA-1 value in the mediamoderation_scan table.
 */
class ScanFilesInScanTable extends Maintenance {

	private LBFactory $loadBalancerFactory;
	private MediaModerationDatabaseLookup $mediaModerationDatabaseLookup;
	private MediaModerationFileScanner $mediaModerationFileScanner;
	private JobQueueGroup $jobQueueGroup;
	private StatusFormatter $statusFormatter;

	private ?string $lastChecked;
	/** @var array If --use-jobqueue is specified, holds the SHA-1 values currently being processed by the job queue. */
	private array $sha1ValuesBeingProcessed = [];

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'MediaModeration' );
		$this->addDescription( 'Maintenance script to scan files listed in the mediamoderation_scan table.' );

		$this->addOption(
			'last-checked',
			'Only scan files (referenced by their SHA-1 value internally) where the last attempted scan ' .
			'was before this date (including never checked files). The default is to filter for files last attempted ' .
			"to be scanned before today. To only scan files that have never been scanned before specify 'never'. The " .
			'accepted format is YYYYMMDD or a timestamp supported by ConvertibleTimestamp. Files that have been ' .
			'successfully scanned (i.e. the match status is not null) are not re-scanned by this script.',
		);
		$this->addOption(
			'use-jobqueue',
			'Scan files concurrently using the job queue. Each job scans one SHA-1 and are added in ' .
			'batches of --batch-size. The script waits to add more jobs until the number of jobs left processing ' .
			'less than --poll-until. Using the job queue increases the speed of scanning, but disables output to ' .
			'console about the status of scans as these are handled by jobs which produce no console output.',
		);
		$this->addOption(
			'sleep',
			'Sleep time (in seconds) between every batch of SHA-1 values scanned. Default: 1',
			false,
			true
		);
		$this->addOption(
			'poll-sleep',
			'Sleep time (in seconds) between every poll to check for completed scanning jobs. This is done ' .
			'so that the script does not add more jobs to scan SHA-1 values until the SHA-1 values being currently ' .
			'processed is equal or less than --poll-until. Does nothing if the --use-jobqueue option is not ' .
			'specified. Default: 1',
			false,
			true
		);
		$this->addOption(
			'poll-until',
			'If --use-jobqueue is specified, used to wait until there are this or less SHA-1s being ' .
			'currently being processed by the job queue. This is checked via polling and the speed of polling is ' .
			'controlled by --poll-sleep. The default for this option is half of the value of --batch-size (which ' .
			'is 200 by default).',
			false,
			true
		);
		$this->addOption(
			'max-polls',
			'If --use-jobqueue is specified, then this controls the number of times that the status of ' .
			'scans in the job queue are polled. If the number of times polled exceeds this value the array that ' .
			'tracks the SHA-1 values currently being processed is emptied to avoid failed jobs causing the script ' .
			'to infinitely loop.',
			false,
			true
		);
		$this->addOption(
			'verbose',
			'Enables verbose mode which prints out information once a SHA-1 has finished being scanned.' .
			'If --use-jobqueue is specified, this instead prints out information about the jobs being queued.',
			false,
			false,
			'v'
		);

		$this->setBatchSize( 200 );
	}

	public function execute() {
		$this->initServices();
		$this->parseLastCheckedTimestamp();

		foreach ( $this->generateSha1ValuesForScan() as $sha1 ) {
			if ( $this->hasOption( 'use-jobqueue' ) ) {
				// Push scan jobs to the job queue if --use-jobqueue is set.
				// To monitor the status of scans when using the job queue it
				// is intended that the user monitors statsd / the logging channel.
				try {
					$this->jobQueueGroup->push( new JobSpecification(
						'mediaModerationScanFileJob',
						[ 'sha1' => $sha1 ]
					) );
				} catch ( JobQueueError $e ) {
					// If the job failed to be inserted, then catch the exception and sleep as this can occur if the
					// server is experiencing instability.
					sleep( intval( $this->getOption( 'sleep', 1 ) ) );
				}
			} else {
				$scanStatus = $this->mediaModerationFileScanner->scanSha1( $sha1 );
				$this->maybeOutputVerboseScanResult( $sha1, $scanStatus );
			}
		}
	}

	/**
	 * Outputs verbose information about the status of a scan for a provided SHA-1 if
	 * verbose mode is enabled via the --verbose command line argument.
	 *
	 * @param string $sha1 The SHA-1 that was just checked
	 * @param StatusValue $checkResult The StatusValue as returned by MediaModerationFileScanner::scanSha1
	 * @return void
	 */
	protected function maybeOutputVerboseScanResult( string $sha1, StatusValue $checkResult ) {
		if ( !$this->hasOption( 'verbose' ) ) {
			return;
		}
		// Output any warnings or errors.
		if ( !$checkResult->isGood() && count( $checkResult->getErrors() ) ) {
			$this->error( "SHA-1 $sha1\n" );
			if ( count( $checkResult->getErrors() ) === 1 ) {
				$this->error( '* ' . $this->statusFormatter->getWikiText( $checkResult ) . "\n" );
			} elseif ( count( $checkResult->getErrors() ) > 1 ) {
				$this->error( $this->statusFormatter->getWikiText( $checkResult ) );
			}
		}
		$outputString = "SHA-1 $sha1: ";
		if ( $checkResult->getValue() === null ) {
			$outputString .= "Scan failed.\n";
			// If the scan failed, make this an error output.
			$this->error( $outputString );
			return;
		}
		if ( $checkResult->getValue() ) {
			$outputString .= "Positive match.\n";
		} else {
			$outputString .= "No match.\n";
		}
		$this->output( $outputString );
	}

	protected function initServices() {
		$services = $this->getServiceContainer();
		$this->loadBalancerFactory = $services->getDBLoadBalancerFactory();
		$this->mediaModerationDatabaseLookup = $services->get( 'MediaModerationDatabaseLookup' );
		$this->mediaModerationFileScanner = $services->get( 'MediaModerationFileScanner' );
		$this->jobQueueGroup = $services->getJobQueueGroup();
		$this->statusFormatter = $services->getFormatterFactory()->getStatusFormatter( RequestContext::getMain() );
	}

	/**
	 * Parse the 'last-checked' timestamp provided via the command line,
	 * and cause a fatal error if it cannot be parsed.
	 *
	 * @return void
	 */
	protected function parseLastCheckedTimestamp() {
		$lastChecked = $this->getOption(
			'last-checked',
			// Subtract one day from the current date for the default of 'last-checked'
			ConvertibleTimestamp::time() - 60 * 60 * 24
		);
		// If the 'last-checked' option is the string "never", then convert this to null.
		if ( $lastChecked === "never" ) {
			$this->lastChecked = null;
		} elseif ( strlen( $lastChecked ) === 8 && $lastChecked === strval( intval( $lastChecked ) ) ) {
			// The 'last-checked' argument is likely to be in the form YYYYMMDD because:
			// * The length of the argument is 8 (which is the length of a YYYYMMDD format)
			// * The intval of the 'last-checked' parameter can be converted to an integer
			//    and from a string without any changes in value (thus it must be an integer
			//    in string form).
			// Convert it to a TS_MW timestamp by adding 000000 to the end (the time component).
			if (
				$lastChecked === $this->mediaModerationDatabaseLookup
					->getDateFromTimestamp( ConvertibleTimestamp::now() )
			) {
				$this->fatalError( 'The --last-checked argument cannot be the current date.' );
			}
			$this->lastChecked = $lastChecked . '000000';
		} elseif ( ConvertibleTimestamp::convert( TS_MW, $lastChecked ) ) {
			// If the 'last-checked' argument is recognised as a timestamp by ConvertibleTimestamp::convert,
			// then get the date part and discard the time part (replacing it with 000000).
			$dateFromTimestamp = $this->mediaModerationDatabaseLookup->getDateFromTimestamp( $lastChecked );
			if (
				$dateFromTimestamp === $this->mediaModerationDatabaseLookup
					->getDateFromTimestamp( ConvertibleTimestamp::now() )
			) {
				$this->fatalError( 'The --last-checked argument cannot be the current date.' );
			}
			$this->lastChecked = $dateFromTimestamp . '000000';
		} else {
			// The 'last-checked' argument could not be parsed, so raise an error
			$this->fatalError(
				'The --last-checked argument passed to this script could not be parsed. This can take a ' .
				'timestamp in string form, or a date in YYYYMMDD format.'
			);
		}
	}

	/**
	 * Generates SHA-1 values for to be scanned. This function pauses for the
	 * specified number of seconds after each batch of SHA-1 values.
	 *
	 * @return \Generator
	 */
	protected function generateSha1ValuesForScan(): \Generator {
		do {
			$batch = $this->mediaModerationDatabaseLookup->getSha1ValuesForScan(
				$this->getBatchSize() ?? 200,
				$this->lastChecked,
				SelectQueryBuilder::SORT_ASC,
				$this->sha1ValuesBeingProcessed,
				MediaModerationDatabaseLookup::NULL_MATCH_STATUS
			);
			// Store the number of rows returned to determine if another batch should be performed.
			$lastBatchRowCount = count( $batch );
			yield from $batch;
			// Sleep for the number of seconds specified in the 'sleep' option.
			sleep( intval( $this->getOption( 'sleep', 1 ) ) );
			if ( $this->hasOption( 'use-jobqueue' ) ) {
				// Wait until the number of SHA-1 values being processed drops below a specific count.
				$this->waitForJobQueueSize( $batch );
			}
			// Wait for replication so that updates to the mms_is_match and mms_last_checked
			// on the rows processed in this batch are replicated to replica DBs.
			$this->loadBalancerFactory->waitForReplication();
		} while ( $lastBatchRowCount !== 0 );
	}

	/**
	 * Waits for the number of SHA-1 values currently being processed using jobs to be less
	 * than half the batch size.
	 *
	 * When in verbose mode this method also prints out information about the SHA-1 values being processed.
	 *
	 * @param array $batch The new batch of SHA-1s being processed. If no batch was added,
	 *   specify an empty array.
	 * @return void
	 */
	protected function waitForJobQueueSize( array $batch ) {
		$pollUntil = intval( $this->getOption( 'poll-until', floor( ( $this->getBatchSize() ?? 200 ) / 2 ) ) );
		if ( $this->hasOption( 'verbose' ) ) {
			// If in verbose mode, print out the batch that was just added to the console.
			$batchSize = count( $batch );
			$this->output(
				"Added $batchSize SHA-1 value(s) for scanning via the job queue: " .
				implode( ', ', $batch ) . "\n"
			);
		}
		// Add the new SHA-1 values being processed by the job queue to the array keeping track
		// of the job queue count. Needed because JobQueueEventBus does not return the current
		// job queue count.
		$this->sha1ValuesBeingProcessed = array_merge( $this->sha1ValuesBeingProcessed, $batch );
		// Wait until at least half of the SHA-1's have been updated to have mms_last_checked as the current date
		// or we have looped more than --max-polls times.
		$numberOfTimesPolled = 0;
		if ( !count( $this->sha1ValuesBeingProcessed ) ) {
			// Return early if sha1ValuesBeingProcessed is empty, as we have nothing to wait for.
			return;
		}
		do {
			if ( $this->hasOption( 'verbose' ) ) {
				// If in verbose mode, print out how many jobs are currently processing and how many we are
				// waiting to complete before adding more.
				$sha1sBeingProcessedCount = count( $this->sha1ValuesBeingProcessed );
				$this->output(
					"$sha1sBeingProcessedCount SHA-1 value(s) currently being processed via jobs. " .
					"Waiting until there are $pollUntil or less SHA-1 value(s) being processed before " .
					"adding more jobs.\n"
				);
			}
			$this->sha1ValuesBeingProcessed = array_diff(
				$this->sha1ValuesBeingProcessed, $this->pollSha1ValuesForScanCompletion()
			);
			sleep( intval( $this->getOption( 'poll-sleep', 1 ) ) );
			$numberOfTimesPolled++;
		} while (
			count( $this->sha1ValuesBeingProcessed ) > $pollUntil &&
			$numberOfTimesPolled < $this->getOption( 'max-polls', 60 )
		);
		// If the we polled too many times, then reset the internal array of SHA-1s being processed as it is probably
		// out of sync to the actual number of jobs running.
		if ( $numberOfTimesPolled >= $this->getOption( 'max-polls', 60 ) ) {
			if ( $this->hasOption( 'verbose' ) ) {
				$this->error(
					'The internal array of SHA-1 values being processed has been cleared as more than ' .
					"{$this->getOption( 'max-polls', 60 )} polls have occurred.\n"
				);
			}
			$this->sha1ValuesBeingProcessed = [];
		}
	}

	protected function pollSha1ValuesForScanCompletion(): array {
		$dbr = $this->mediaModerationDatabaseLookup->getDb( IDBAccessObject::READ_NORMAL );
		// Wait for replication to occur to avoid polling a out-of-date replica DB.
		$this->loadBalancerFactory->waitForReplication();
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( 'mms_sha1' )
			->from( 'mediamoderation_scan' )
			->where( [
				$dbr->expr(
					'mms_last_checked',
					'>',
					$this->mediaModerationDatabaseLookup->getDateFromTimestamp( $this->lastChecked )
				),
			] );
		if ( count( $this->sha1ValuesBeingProcessed ) ) {
			$queryBuilder->andWhere( [
				'mms_sha1' => $this->sha1ValuesBeingProcessed
			] );
		}
		return $queryBuilder->caller( __METHOD__ )->fetchFieldValues();
	}
}

$maintClass = ScanFilesInScanTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
