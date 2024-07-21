<?php

namespace MediaWiki\Extension\MediaModeration\Maintenance;

use LoggedUpdateMaintenance;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileFactory;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor;
use MediaWiki\FileRepo\File\FileSelectQueryBuilder;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class ImportExistingFilesToScanTable extends LoggedUpdateMaintenance {

	/** @var string[] The DB tables that have images to be imported to mediamoderation_scan. */
	public const TABLES_TO_IMPORT_FROM = [
		'image',
		'filearchive',
		'oldimage',
	];

	private IReadableDatabase $dbr;
	private MediaModerationFileProcessor $mediaModerationFileProcessor;
	private MediaModerationDatabaseLookup $mediaModerationDatabaseLookup;
	private MediaModerationFileFactory $mediaModerationFileFactory;
	private MediaModerationFileLookup $mediaModerationFileLookup;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'MediaModeration' );
		$this->addDescription( 'Populates the mediamoderation_scan table with existing images from the wiki.' );
		$this->addOption(
			'sleep',
			'Sleep time (in seconds) between every batch. Default: 1',
			false,
			true
		);
		$this->addOption(
			'start-timestamp',
			'The timestamp which to start importing files from. Default is for no timestamp start point ' .
			'(which means importing all images)',
			false,
			true
		);
		$this->addOption(
			'table',
			'Allows specifying which table(s) files should be imported from. Default is all supported tables.',
			false,
			true,
			false,
			true
		);
		$this->addOption(
			'mark-complete',
			'Allows controlling whether this script should be considered completely run for the purposes ' .
			'of the updatelog. If provided the script will be marked as complete. ' .
			"Default is to consider the script completely run if the 'table' and 'start-timestamp' options were left " .
			'as the default and the script does not error out.',
		);
	}

	/** @inheritDoc */
	protected function getUpdateKey() {
		return __CLASS__;
	}

	/** @inheritDoc */
	protected function doDBUpdates() {
		$services = $this->getServiceContainer();
		$this->dbr = $this->getReplicaDB();
		$this->mediaModerationFileFactory = $services->get( 'MediaModerationFileFactory' );
		$this->mediaModerationFileProcessor = $services->get( 'MediaModerationFileProcessor' );
		$this->mediaModerationDatabaseLookup = $services->get( 'MediaModerationDatabaseLookup' );
		$this->mediaModerationFileLookup = $services->get( 'MediaModerationFileLookup' );

		// Get the list of tables to import images from.
		$tablesToProcess = $this->getTablesToProcess();
		if ( $tablesToProcess === false ) {
			// If the value is false, then return false
			// as this indicates the script did not run.
			return false;
		}

		foreach ( $tablesToProcess as $table ) {
			$batchSize = $this->getBatchSize() ?? 200;
			$this->output( "Now importing rows from the table '$table' in batches of $batchSize.\n" );
			$previousBatchFinalTimestamp = $this->getOption( 'start-timestamp', '' );
			if ( $previousBatchFinalTimestamp ) {
				$this->output(
					"Starting from timestamp $previousBatchFinalTimestamp and importing files with a " .
					"greater timestamp.\n"
				);
			}
			$expectedNumberOfBatches = $this->getEstimatedNumberOfBatchesForTable(
				$table, $previousBatchFinalTimestamp
			);
			$batchNo = 1;
			do {
				$outputString = "Batch $batchNo of ~$expectedNumberOfBatches";
				if ( $previousBatchFinalTimestamp ) {
					$outputString .= " with rows starting at timestamp $previousBatchFinalTimestamp";
				}
				$this->output( $outputString . ".\n" );
				[
					$filesLeft,
					$previousBatchFinalTimestamp
				] = $this->performBatch( $table, $previousBatchFinalTimestamp );
				sleep( intval( $this->getOption( 'sleep', 1 ) ) );
				$this->waitForReplication();
				$batchNo += 1;
			} while ( $filesLeft );
		}
		$returnValue = $this->generateDBUpdatesReturnValue();
		if ( $this->hasOption( 'force' ) ) {
			// If the script was run with the force option, then don't
			// print out about how the script has been or has not been
			// marked as completed.
			return $returnValue;
		}
		if ( $returnValue ) {
			$this->output( "Script marked as completed (added to updatelog).\n" );
		} else {
			$this->output(
				'Script not marked as completed (not added to updatelog). The script was marked as not complete ' .
				"because not all the images on the wiki were processed in this run of the script.\n" .
				'To mark the script as complete and not have it run again through update.php, make sure to run the ' .
				"script again with the 'mark-complete' option specified. You should only do this once you are sure " .
				"that all the images on the wiki have been imported.\n"
			);
		}
		return $returnValue;
	}

	/**
	 * Return the value that should be used as the return value
	 * of ::doDBUpdates. This value depends on the options
	 * passed to the script.
	 *
	 * If true is returned, it should only be done if the script
	 * either has imported all images or the caller of the maintenance
	 * script has specifically intended for the script to marked as
	 * complete.
	 *
	 * @return bool
	 */
	protected function generateDBUpdatesReturnValue(): bool {
		// Return true if mark-complete is specified, or if both:
		// * start-timestamp is not specified or an empty string, and
		// * table is not specified or includes all tables listed in self::TABLES_TO_IMPORT_FROM.
		return $this->hasOption( 'mark-complete' ) ||
			(
				$this->getOption( 'start-timestamp', '' ) === '' &&
				$this->getOption( 'table', self::TABLES_TO_IMPORT_FROM ) == self::TABLES_TO_IMPORT_FROM
			);
	}

	/**
	 * Processes the user supplied list of tables to process,
	 * with the default being all supported tables.
	 *
	 * Prints an error if the supplied arguments are invalid.
	 *
	 * @return false|array The list of tables to process, or false if the list was not valid.
	 */
	protected function getTablesToProcess() {
		$tablesToProcess = $this->getOption( 'table', self::TABLES_TO_IMPORT_FROM );
		if ( !count( $tablesToProcess ) ) {
			$this->error( "The array of tables to have images imported from cannot be empty.\n" );
			return false;
		}
		foreach ( $tablesToProcess as $table ) {
			if ( !in_array( $table, self::TABLES_TO_IMPORT_FROM ) ) {
				$this->error( "The table option value '$table' is not a valid table to import images from.\n" );
				return false;
			}
		}
		return $tablesToProcess;
	}

	/**
	 * Gets the expected number of batches needed to process a table.
	 * This is used just for visual display and the actual number of batches
	 * may be higher or lower.
	 *
	 * @param string $table
	 * @param string $startTimestamp The timestamp that the processing will start from.
	 * @return int
	 */
	protected function getEstimatedNumberOfBatchesForTable( string $table, string $startTimestamp ): int {
		// Get the row count for the $table.
		$queryBuilder = $this->dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( $table );
		if ( $startTimestamp ) {
			$queryBuilder->where( $this->dbr->expr(
				$this->mediaModerationFileLookup->getTimestampFieldForTable( $table ),
				'>=',
				$this->dbr->timestamp( $startTimestamp )
			) );
		}
		$rowCountInTable = $queryBuilder
			->caller( __METHOD__ )
			->fetchField();
		// If the row count is zero, then one batch will be performed.
		if ( !$rowCountInTable ) {
			return 1;
		}
		// The expected batch count is the number of rows in the table
		// divided by the batch size. This may be higher than the actual
		// batch count, as it may be temporarily increased to prevent
		// infinite loops.
		$batchSize = $this->getBatchSize() ?? 200;
		$expectedBatchesCount = ceil( $rowCountInTable / $batchSize );
		if ( $rowCountInTable % $batchSize === 0 ) {
			// If the batch size divides the row count without a remainder, then
			// the expected batch count needs to be increased by one as one
			// more batch will be performed at the end with no rows found.
			$expectedBatchesCount += 1;
		}
		return $expectedBatchesCount;
	}

	/**
	 * Gets the temporary batch size for use by ::processBatch if it was provided
	 * with $shouldRaiseBatchSize as the boolean 'true'. This is one more than
	 * the number of files with the $previousBatchFinalTimestamp.
	 *
	 * @param FileSelectQueryBuilder $fileSelectQueryBuilder The cloned FileSelectQueryBuilder that is being
	 *   built in ::getSelectFileQueryBuilder. This needs to be cloned to avoid issues with this
	 *   method modifying the query builder.
	 * @param string $timestampField
	 * @param string $previousBatchFinalTimestamp
	 * @return int
	 */
	protected function getTemporaryBatchSize(
		FileSelectQueryBuilder $fileSelectQueryBuilder, string $timestampField, string $previousBatchFinalTimestamp
	): int {
		$filesWithTheCutoffTimestamp = (int)$fileSelectQueryBuilder
			->clearFields()
			->field( 'COUNT(*)' )
			->where( $this->dbr->expr( $timestampField, '=', $this->dbr->timestamp( $previousBatchFinalTimestamp ) ) )
			->caller( __METHOD__ )
			->fetchField();
		// Sanity check that the new batch size would actually be larger (otherwise
		// leave the batch size as is as it will be fine).
		if ( $filesWithTheCutoffTimestamp >= ( $this->getBatchSize() ?? 200 ) ) {
			$batchSize = $filesWithTheCutoffTimestamp + 1;
			$this->output(
				"Temporarily raised the batch size to $batchSize due to files with the same upload timestamp. " .
				"This is done to prevent an infinite loop. Consider raising the batch size to avoid this.\n"
			);
			return $batchSize;
		}
		return $this->getBatchSize() ?? 200;
	}

	/**
	 * Gets the appropriate FileSelectQueryBuilder for the $table and
	 * applies the WHERE conditions, ORDER BY and LIMIT.
	 *
	 * @param string $table The table name currently being processed.
	 * @param string $previousBatchFinalTimestamp The timestamp which the last batch stopped at. This
	 *   is used to filter for files with this timestamp or a newer timestamp.
	 * @param bool $shouldRaiseBatchSize Used to indicate that the previous batch ended and started on
	 *   the same timestamp, so this batch should reattempt that timestamp
	 *   but with a temporarily raised batch size to account for this.
	 * @return FileSelectQueryBuilder
	 */
	protected function getFileSelectQueryBuilder(
		string $table, string $previousBatchFinalTimestamp, bool $shouldRaiseBatchSize
	): FileSelectQueryBuilder {
		// Get the appropriate FileSelectQueryBuilder using MediaModerationDatabaseLookup::getFileSelectQueryBuilder
		$fileSelectQueryBuilder = $this->mediaModerationFileLookup->getFileSelectQueryBuilder( $table );
		$timestampField = $this->mediaModerationFileLookup->getTimestampFieldForTable( $table );
		$batchSize = $this->getBatchSize() ?? 200;
		if ( $shouldRaiseBatchSize ) {
			// If the previous batch started and ended on the same timestamp,
			// then temporarily raise the batch count to 1 more than the number
			// of files with this timestamp to avoid an infinite loop.
			$batchSize = $this->getTemporaryBatchSize(
				clone $fileSelectQueryBuilder,
				$timestampField,
				$previousBatchFinalTimestamp
			);
		}
		// If the timestamp is not empty, filter for entries with a greater timestamp
		// than the cutoff timestamp.
		if ( $previousBatchFinalTimestamp ) {
			$fileSelectQueryBuilder
				->where( $this->dbr->expr(
					$timestampField, '>=', $this->dbr->timestamp( $previousBatchFinalTimestamp ) ) );
		}
		// Order by the timestamp (oldest to newest) and set the limit as the batch size.
		$fileSelectQueryBuilder
			->orderBy( $timestampField, SelectQueryBuilder::SORT_ASC )
			->limit( $batchSize );
		return $fileSelectQueryBuilder;
	}

	/**
	 * Gets the rows for a batch along with the timestamp for the last file in the batch.
	 *
	 * @param string $table
	 * @param string $previousBatchFinalTimestamp
	 * @return array The rows for the batch, the timestamp for the last file in the results list, and the
	 *   LIMIT used for the batch.
	 */
	protected function getRowsForBatch( string $table, string $previousBatchFinalTimestamp ): array {
		// Get the FileSelectQueryBuilder with everything but the caller specified.
		$fileSelectQueryBuilder = $this->getFileSelectQueryBuilder(
			$table, $previousBatchFinalTimestamp, false
		);
		// Specify the caller and then get the rows from the DB.
		$rows = $fileSelectQueryBuilder
			->caller( __METHOD__ )
			->fetchResultSet();
		$lastFileTimestamp = $previousBatchFinalTimestamp;
		// Check whether the last file in this batch has the same timestamp as in
		// $previousBatchFinalTimestamp. If so, then increase the batch size to
		// prevent an infinite loop which would be caused by processing the same
		// files over and over again with that timestamp.
		if ( $rows->numRows() ) {
			$rows->seek( $rows->numRows() - 1 );
			$lastFileObject = $this->mediaModerationFileFactory->getFileObjectForRow( $rows->fetchObject(), $table );
			if ( $previousBatchFinalTimestamp === $lastFileObject->getTimestamp() ) {
				// Temporarily raise the batch size for the next batch as the
				// last timestamp in this batch is the same as the last timestamp
				// for the last batch.
				$fileSelectQueryBuilder = $this->getFileSelectQueryBuilder(
					$table, $previousBatchFinalTimestamp, true
				);
				$rows = $fileSelectQueryBuilder
					->caller( __METHOD__ )
					->fetchResultSet();
				$rows->seek( $rows->numRows() - 1 );
				$lastFileObject = $this->mediaModerationFileFactory->getFileObjectForRow(
					$rows->fetchObject(), $table
				);
			}
			// Store the timestamp for the last file, and return it to the caller later in this method.
			$lastFileTimestamp = $lastFileObject->getTimestamp();
			// Reset the position of the pointer for the caller to be able to use a foreach loop on $rows.
			$rows->rewind();
		}
		return [ $rows, $lastFileTimestamp, $fileSelectQueryBuilder->getQueryInfo()['options']['LIMIT'] ];
	}

	/**
	 * Perform a batch of imports to the mediamoderation_scan table from the $table
	 * starting at the $lastFileTimestamp going towards newer files.
	 *
	 * @param string $table
	 * @param string $previousBatchFinalTimestamp
	 * @return array First value is whether another batch should be run, second value is the new value of
	 *   $previousBatchFinalTimestamp, and third value is the new value of $shouldRaiseBatchSize
	 */
	protected function performBatch( string $table, string $previousBatchFinalTimestamp ): array {
		[ $rows, $lastFileTimestamp, $batchSizeUsedForBatch ] = $this->getRowsForBatch(
			$table, $previousBatchFinalTimestamp
		);
		foreach ( $rows as $row ) {
			// Get the File or ArchivedFile object for this $row.
			$fileObject = $this->mediaModerationFileFactory->getFileObjectForRow( $row, $table );
			// Exclude any file that has a SHA-1 value that is false or empty.
			// This can happen in some filearchive rows where the image no
			// longer exists.
			// Also check if the SHA-1 exists in the scan table using a replica DB
			// before attempting to insert the file to reduce the number of
			// unnecessary reads on the primary DB.
			if (
				$fileObject->getSha1() &&
				!$this->mediaModerationDatabaseLookup->fileExistsInScanTable( $fileObject )
			) {
				$this->mediaModerationFileProcessor->insertFile( $fileObject );
			}
		}
		// Return false as the first item of the array if the number of rows processed
		// was less than the batch size. This will happen when there are no more images
		// to process.
		return [
			$rows->count() >= $batchSizeUsedForBatch,
			$lastFileTimestamp
		];
	}
}

$maintClass = ImportExistingFilesToScanTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
