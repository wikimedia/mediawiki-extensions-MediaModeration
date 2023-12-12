<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use ArchivedFile;
use Generator;
use InvalidArgumentException;
use LocalFile;
use LocalRepo;
use MediaWiki\FileRepo\File\FileSelectQueryBuilder;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\SelectQueryBuilder;

class MediaModerationFileLookup {

	public const TABLES_USED_FOR_LOOKUP = [
		'image',
		'oldimage',
		'filearchive',
	];

	private LocalRepo $localRepo;
	private MediaModerationFileFactory $mediaModerationFileFactory;

	public function __construct(
		LocalRepo $localRepo,
		MediaModerationFileFactory $mediaModerationFileFactory
	) {
		$this->localRepo = $localRepo;
		$this->mediaModerationFileFactory = $mediaModerationFileFactory;
	}

	/**
	 * Gets the appropriate FileSelectQueryBuilder for the given image $table
	 *
	 * @param string $table One of 'image', 'oldimage', or 'filearchive'
	 * @return FileSelectQueryBuilder
	 * @throws InvalidArgumentException If an unrecognised $table is provided
	 */
	public function getFileSelectQueryBuilder( string $table ): FileSelectQueryBuilder {
		if ( $table === 'image' ) {
			$fileSelectQueryBuilder = FileSelectQueryBuilder::newForFile( $this->localRepo->getReplicaDB() );
		} elseif ( $table === 'oldimage' ) {
			$fileSelectQueryBuilder = FileSelectQueryBuilder::newForOldFile( $this->localRepo->getReplicaDB() );
		} elseif ( $table === 'filearchive' ) {
			$fileSelectQueryBuilder = FileSelectQueryBuilder::newForArchivedFile( $this->localRepo->getReplicaDB() );
		} else {
			throw new InvalidArgumentException( "Unrecognised image table '$table'." );
		}
		return $fileSelectQueryBuilder;
	}

	/**
	 * Gets the timestamp field for the provided $table.
	 *
	 * @param string $table One of 'image', 'oldimage', or 'fileimage'
	 * @return string The timestamp field name
	 * @throws InvalidArgumentException If $table is not one of the three valid options.
	 */
	public function getTimestampFieldForTable( string $table ): string {
		if ( $table === 'image' ) {
			return 'img_timestamp';
		} elseif ( $table === 'oldimage' ) {
			return 'oi_timestamp';
		} elseif ( $table === 'filearchive' ) {
			return 'fa_timestamp';
		} else {
			throw new InvalidArgumentException( "Unrecognised image table '$table'." );
		}
	}

	/**
	 * Gets the SHA-1 field for the provided $table.
	 *
	 * @param string $table One of 'image', 'oldimage', or 'fileimage'
	 * @return string The SHA-1 field name
	 * @throws InvalidArgumentException If $table is not one of the three valid options.
	 */
	private function getSha1FieldForTable( string $table ): string {
		if ( $table === 'image' ) {
			return 'img_sha1';
		} elseif ( $table === 'oldimage' ) {
			return 'oi_sha1';
		} elseif ( $table === 'filearchive' ) {
			return 'fa_sha1';
		} else {
			throw new InvalidArgumentException( "Unrecognised image table '$table'." );
		}
	}

	/**
	 * Returns the row count for rows that have a given
	 * timestamp and optionally a given SHA-1 value.
	 *
	 * Used to prevent issues with paging by timestamp
	 * when the row count being used to page is less
	 * than the row count of rows with a given timestamp.
	 *
	 * @param string $table The table to get the row count from (one of image, oldimage, or filearchive).
	 * @param string $timestamp The given timestamp in a TS_MW format. The count will only include rows
	 *   with this exact timestamp.
	 * @param string|null $sha1 If provided, filter the count to only include rows with the given SHA-1 value.
	 *   To not filter by SHA-1, provide null.
	 * @return int
	 */
	public function getRowCountForTimestamp( string $table, string $timestamp, ?string $sha1 ): int {
		$fileSelectQueryBuilder = $this->getFileSelectQueryBuilder( $table )
			->clearFields()
			->field( 'COUNT(*)' )
			->where( [
				$this->getTimestampFieldForTable( $table ) => $timestamp,
			] );
		if ( $sha1 !== null ) {
			$fileSelectQueryBuilder->where( [
				$this->getSha1FieldForTable( $table ) => $sha1,
			] );
		}
		return $fileSelectQueryBuilder->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Actually performs the SELECT query to get a batch of rows from the given $table.
	 * Used by ::getBatchOfFileRows.
	 *
	 * @param string $table The table to get the batch from (one of image, oldimage, or filearchive).
	 * @param string $startTimestamp The timestamp which to start this batch at (cannot have been used for
	 *    a previous batch to prevent infinite loops). Provide the empty string to start with the newest timestamp.
	 * @param string $sha1 The SHA-1 which rows must have to be selected
	 * @param int $batchSize The maximum number of rows to select
	 *
	 * @return IResultWrapper
	 */
	protected function performBatchQuery(
		string $table, string $startTimestamp, string $sha1, int $batchSize
	): IResultWrapper {
		// Only select rows with the given $sha1
		$queryBuilder = $this->getFileSelectQueryBuilder( $table )
			->where( [
				$this->getSha1FieldForTable( $table ) => $sha1,
			] );
		if ( $startTimestamp ) {
			// Only select rows with that have a timestamp under the $startTimestamp.
			$queryBuilder->where( $this->localRepo->getReplicaDB()->expr(
				$this->getTimestampFieldForTable( $table ),
				'<=',
				$startTimestamp
			) );
		}
		// Select $batchSize rows.
		return $queryBuilder
			->orderBy( $this->getTimestampFieldForTable( $table ), SelectQueryBuilder::SORT_DESC )
			->limit( $batchSize )
			->caller( __METHOD__ )
			->fetchResultSet();
	}

	/**
	 * Returns a batch of rows from $table. The batches will go from largest timestamp to smallest timestamp,
	 * and are constructed such to not return the same row in more than one batch.
	 *
	 * @param string $table The table to get the batch from (one of image, oldimage, or filearchive).
	 * @param string $startTimestamp The timestamp which to start this batch at (cannot have been used for
	 *   a previous batch to prevent infinite loops). Provide the empty string to start with the newest timestamp.
	 * @param string $sha1 The SHA-1 which rows must have to be selected
	 * @param int $batchSize The maximum number of rows return in the batch
	 *
	 * @return array First item being the IResultWrapper and the second being the value for $startTimestamp of the
	 *   next batch.
	 */
	protected function getBatchOfFileRows(
		string $table, string $startTimestamp, string $sha1, int $batchSize
	): array {
		// Check that rows with the $sha1 and $startTimestamp do not exceed $batchSize. Otherwise, raise the $batchSize
		// to prevent infinite loops.
		$rowsWithStartTimestamp = $this->getRowCountForTimestamp( $table, $startTimestamp, $sha1 );
		if ( $rowsWithStartTimestamp > $batchSize ) {
			// Increase the batch size to account for this, as if not the next batch would start with
			// the same rows causing an infinite loop.
			$batchSize = $rowsWithStartTimestamp;
		}
		// Get the batch which contains $batchSize + 1 rows. The added row is to ensure proper paging.
		$resultWrapper = $this->performBatchQuery( $table, $startTimestamp, $sha1, $batchSize + 1 );
		if ( $resultWrapper->count() < $batchSize + 1 ) {
			// If the row count returned is less than the batch size, then just return the result
			// set along with the indication that no more batches can be found.
			return [ $resultWrapper, false ];
		}
		// Get the smallest timestamp in this batch.
		$resultWrapper->seek( $resultWrapper->count() - 1 );
		$timestampToRemoveFromBatch = $resultWrapper->fetchRow()[$this->getTimestampFieldForTable( $table )];
		// Rewind the results wrapper to the first row.
		$resultWrapper->rewind();
		// To ensure that a given file is only present within one batch, the batches must contain upload timestamp
		// values which are not present in any other batch. This is because the upload timestamp is the way to separate
		// the results when batching, and starting a new batch with a timestamp used in a previous batch would mean
		// some of the files are listed twice.
		// Removing the rows with the smallest timestamp addresses this by ensuring all the files are in the
		// next batch.
		$resultsToReturn = [];
		foreach ( $resultWrapper as $row ) {
			$rowAsArray = (array)$row;
			if ( $rowAsArray[$this->getTimestampFieldForTable( $table )] !== $timestampToRemoveFromBatch ) {
				$resultsToReturn[] = $row;
			}
		}
		// Return the modified results.
		return [ new FakeResultWrapper( $resultsToReturn ), $timestampToRemoveFromBatch ];
	}

	/**
	 * Gets LocalFile, OldFile, and ArchivedFile objects with a given SHA-1 from the
	 * local wiki.
	 *
	 * This method generates these entries instead of returning all objects as
	 * only one object is usually needed for the purposes of scanning. In the
	 * case of the image not having a thumbnail or otherwise having an problem,
	 * further images may be needed.
	 *
	 * The order in which these objects are generated is the order of the tables
	 * in self::TABLES_USED_FOR_LOOKUP and then their upload timestamp starting
	 * with the newest file.
	 *
	 * @param string $sha1 The SHA-1 used for lookup
	 * @param int $batchSize The number of files to select per select query. Increase this
	 *   number if you intend to use all the files returned by this query.
	 * @return Generator<LocalFile|ArchivedFile>
	 */
	public function getFileObjectsForSha1( string $sha1, int $batchSize = 5 ): Generator {
		// Process each image table in the order defined in ::TABLES_USED_FOR_LOOKUP
		foreach ( self::TABLES_USED_FOR_LOOKUP as $table ) {
			// Lookup rows from the image $table where the SHA-1 for that row is
			// the same as in $sha1. Order these by upload timestamp and limit
			// each batch selected from the DB to $batchSize rows.
			$startTimestampForBatch = '';
			do {
				[ $batch, $startTimestampForBatch ] = $this->getBatchOfFileRows(
					$table,
					$startTimestampForBatch,
					$sha1,
					$batchSize
				);
				foreach ( $batch as $row ) {
					// Yield the row as a LocalFile or ArchivedFile object.
					yield $this->mediaModerationFileFactory->getFileObjectForRow( $row, $table );
				}
			} while ( $startTimestampForBatch );
		}
	}
}
