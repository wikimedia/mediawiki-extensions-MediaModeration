<?php

namespace MediaWiki\Extension\MediaModeration\Maintenance;

use Maintenance;
use MediaWiki\Extension\MediaModeration\PhotoDNA\IMediaModerationPhotoDNAServiceProvider;
use MediaWiki\Extension\MediaModeration\PhotoDNA\Response;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseManager;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor;
use MediaWiki\Language\RawMessage;
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
	private IMediaModerationPhotoDNAServiceProvider $mediaModerationPhotoDNAServiceProvider;
	private MediaModerationFileLookup $mediaModerationFileLookup;
	private MediaModerationDatabaseManager $mediaModerationDatabaseManager;
	private MediaModerationDatabaseLookup $mediaModerationDatabaseLookup;
	private MediaModerationFileProcessor $mediaModerationFileProcessor;
	private StatusFormatter $statusFormatter;

	private ?string $lastChecked;

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
			'sleep',
			'Sleep time (in seconds) between every batch of SHA-1 values scanned. Default: 1',
			false,
			true
		);
		$this->addOption(
			'verbose',
			'Enables verbose mode which prints out information once a SHA-1 has finished being scanned.',
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
			$newMatchStatus = $this->mediaModerationDatabaseLookup->getMatchStatusForSha1( $sha1 );
			// Used so that the SHA-1 is only outputted once by ::maybeOutputVerboseStatusError if it
			// is called multiple times.
			$hasOutputtedSha1 = false;
			foreach ( $this->mediaModerationFileLookup->getFileObjectsForSha1( $sha1 ) as $file ) {
				if ( !$this->mediaModerationFileProcessor->canScanFile( $file ) ) {
					// If this $file cannot be scanned, then try the next file with this SHA-1
					// and if in verbose mode output to the console about this.
					$this->maybeOutputVerboseStatusError(
						StatusValue::newFatal(
							new RawMessage(
								'The file ' . $file->getName() . ' cannot be scanned.'
							)
						),
						$sha1,
						$hasOutputtedSha1
					);
					continue;
				}
				// Run the check using the PhotoDNA API.
				$checkResult = $this->mediaModerationPhotoDNAServiceProvider->check( $file );
				/** @var Response|null $response */
				$response = $checkResult->getValue();
				if ( $response === null || $response->getStatusCode() !== Response::STATUS_OK ) {
					// Assume something is wrong with the thumbnail if the request fails,
					// and just try a new $file with this SHA-1 and output information about
					// the failed request if in verbose mode.
					$this->maybeOutputVerboseStatusError( $checkResult, $sha1, $hasOutputtedSha1 );
					continue;
				}
				$newMatchStatus = $response->isMatch();
				// Stop processing this SHA-1 as we have a result.
				break;
			}
			// Update the match status, even if none of the $file objects could be scanned.
			// If no scanning was successful, then the status will remain
			$this->mediaModerationDatabaseManager->updateMatchStatusForSha1( $sha1, $newMatchStatus );
			// TODO: Send an email if $newMatchStatus is true (T351407).
			$this->maybeOutputVerboseInformation( $sha1, $newMatchStatus );
		}
	}

	/**
	 * Outputs verbose information about the SHA-1 provided if
	 * verbose mode is enabled via the --verbose command line argument.
	 *
	 * @param string $sha1 The SHA-1 that was just checked
	 * @param ?bool $matchStatus The match status determined by the scan
	 * @return void
	 */
	protected function maybeOutputVerboseInformation( string $sha1, ?bool $matchStatus ) {
		if ( !$this->hasOption( 'verbose' ) ) {
			return;
		}
		$outputString = "SHA-1 $sha1: ";
		if ( $matchStatus === null ) {
			$outputString .= "Scan failed.\n";
			// If the scan failed, make this an error output.
			$this->error( $outputString );
			return;
		}
		if ( $matchStatus ) {
			$outputString .= "Positive match.\n";
		} else {
			$outputString .= "No match.\n";
		}
		$this->output( $outputString );
	}

	/**
	 * Outputs verbose information about the provided $checkResult if
	 * verbose mode is enabled via the --verbose command line argument.
	 *
	 * @param StatusValue $checkResult The result returned by IMediaModerationPhotoDNAServiceProvider::check
	 *   or a StatusValue with a RawMessage.
	 * @param string $sha1 The SHA-1 being processed.
	 * @param bool &$hasOutputtedSha1 Whether the SHA-1 currently being processed has already been outputted to
	 *   the console.
	 * @return void
	 */
	protected function maybeOutputVerboseStatusError(
		StatusValue $checkResult, string $sha1, bool &$hasOutputtedSha1
	) {
		if ( !$this->hasOption( 'verbose' ) ) {
			return;
		}
		if ( !$hasOutputtedSha1 ) {
			$this->error( "SHA-1 $sha1\n" );
			$hasOutputtedSha1 = true;
		}
		$this->error( '...' . $this->statusFormatter->getWikiText( $checkResult ) . "\n" );
	}

	protected function initServices() {
		$services = $this->getServiceContainer();
		$this->loadBalancerFactory = $services->getDBLoadBalancerFactory();
		$this->mediaModerationPhotoDNAServiceProvider = $services->get( 'MediaModerationPhotoDNAServiceProvider' );
		$this->mediaModerationFileLookup = $services->get( 'MediaModerationFileLookup' );
		$this->mediaModerationDatabaseManager = $services->get( 'MediaModerationDatabaseManager' );
		$this->mediaModerationDatabaseLookup = $services->get( 'MediaModerationDatabaseLookup' );
		$this->mediaModerationFileProcessor = $services->get( 'MediaModerationFileProcessor' );
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
			$this->lastChecked = $lastChecked . '000000';
		} elseif ( ConvertibleTimestamp::convert( TS_MW, $lastChecked ) ) {
			// If the 'last-checked' argument is recognised as a timestamp by ConvertibleTimestamp::convert,
			// then get the date part and discard the time part (replacing it with 000000).
			$this->lastChecked = $this->mediaModerationDatabaseLookup->getDateFromTimestamp( $lastChecked ) . '000000';
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
				MediaModerationDatabaseLookup::NULL_MATCH_STATUS
			);
			// Store the number of rows returned to determine if another batch should be performed.
			$lastBatchRowCount = count( $batch );
			yield from $batch;
			// Sleep for the number of seconds specified in the 'sleep' option.
			sleep( intval( $this->getOption( 'sleep', 1 ) ) );
			// Wait for replication so that updates to the mms_is_match and mms_last_checked
			// on the rows processed in this batch are replicated to replica DBs.
			$this->loadBalancerFactory->waitForReplication();
		} while ( $lastBatchRowCount !== 0 );
	}
}

$maintClass = ScanFilesInScanTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
