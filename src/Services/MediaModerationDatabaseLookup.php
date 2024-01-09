<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use ArchivedFile;
use File;
use IDBAccessObject;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampException;

class MediaModerationDatabaseLookup implements IDBAccessObject {

	public const ANY_MATCH_STATUS = 'any';
	public const POSITIVE_MATCH_STATUS = '1';
	public const NEGATIVE_MATCH_STATUS = '0';
	public const NULL_MATCH_STATUS = null;

	private IConnectionProvider $connectionProvider;

	public function __construct( IConnectionProvider $connectionProvider ) {
		$this->connectionProvider = $connectionProvider;
	}

	/**
	 * Returns whether the given $file exists in the mediamoderation_scan table.
	 *
	 * @param File|ArchivedFile $file
	 * @param int $flags IDBAccessObject flags. Does not support READ_LOCKING or READ_EXCLUSIVE
	 * @return bool
	 */
	public function fileExistsInScanTable( $file, int $flags = self::READ_NORMAL ): bool {
		$db = $this->getDb( $flags );
		return (bool)$db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'mediamoderation_scan' )
			->where( [ 'mms_sha1' => $file->getSha1() ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Returns the match status for a given SHA-1. If the SHA-1 does not
	 * exist in the mediamoderation_scan table, this method will return null.
	 *
	 * @param string $sha1
	 * @param int $flags IDBAccessObject flags. Does not support READ_LOCKING or READ_EXCLUSIVE
	 * @return bool|null The match status (null indicates the SHA-1 hasn't been scanned)
	 */
	public function getMatchStatusForSha1( string $sha1, int $flags = self::READ_NORMAL ): ?bool {
		$db = $this->getDb( $flags );
		$rawMatchStatus = $db->newSelectQueryBuilder()
			->select( 'mms_is_match' )
			->from( 'mediamoderation_scan' )
			->where( [ 'mms_sha1' => $sha1 ] )
			->caller( __METHOD__ )
			->fetchField();
		if ( is_string( $rawMatchStatus ) ) {
			return boolval( $rawMatchStatus );
		} else {
			return null;
		}
	}

	/**
	 * Gets the IReadableDatabase object for the virtual-mediamoderation DB domain
	 * for the given $flags.
	 *
	 * @param int $flags IDBAccessObject flags.
	 * @return IReadableDatabase
	 */
	public function getDb( int $flags ): IReadableDatabase {
		if ( $flags & self::READ_LATEST ) {
			return $this->connectionProvider->getPrimaryDatabase( 'virtual-mediamoderation' );
		} else {
			return $this->connectionProvider->getReplicaDatabase( 'virtual-mediamoderation' );
		}
	}

	/**
	 * Converts a given timestamp to a string representing the date in the format YYYYMMDD.
	 *
	 * @param ConvertibleTimestamp|string|int $timestamp A ConvertibleTimestamp or timestamp recognised by
	 *   ConvertibleTimestamp.
	 * @return string The timestamp as a date in the form YYYYMMDD
	 * @throws TimestampException If the $timestamp cannot be parsed
	 */
	public function getDateFromTimestamp( $timestamp ): string {
		// Convert the $timestamp to a ConvertibleTimestamp instance
		if ( !( $timestamp instanceof ConvertibleTimestamp ) ) {
			$timestamp = new ConvertibleTimestamp( $timestamp );
		}
		// Get the timestamp as in TS_MW form (YYYMMDDHHMMSS)
		$timestampAsTSMW = $timestamp->getTimestamp( TS_MW );
		// Return the first 8 characters of the TS_MW timestamp, which
		// means the YYYYMMDD part.
		return substr( $timestampAsTSMW, 0, 8 );
	}

	/**
	 * Returns a SelectQueryBuilder that can be used to query SHA-1 values for a scan.
	 *
	 * The parameters to this method allow filtering for rows with a specific match status and/or rows that were
	 * last checked before or at a particular date.
	 *
	 * @param ConvertibleTimestamp|int|string|null $lastChecked See ::getSha1ValuesForScan
	 * @param string $direction See ::getSha1ValuesForScan
	 * @param array $excludedSha1Values See ::getSha1ValuesForScan
	 * @param string|null $matchStatus See ::getSha1ValuesForScan
	 * @return SelectQueryBuilder
	 * @throws TimestampException If the $lastChecked timestamp could not be parsed as a valid timestamp.
	 */
	protected function newSelectQueryBuilderForScan(
		$lastChecked, string $direction, array $excludedSha1Values, ?string $matchStatus = self::ANY_MATCH_STATUS
	): SelectQueryBuilder {
		// Get a replica DB connection.
		$dbr = $this->getDb( self::READ_NORMAL );
		// Create a SelectQueryBuilder that reads from the mediamoderation_scan table.
		// The fields to read is set by the callers of this method.
		$selectQueryBuilder = $dbr->newSelectQueryBuilder()
			->from( 'mediamoderation_scan' );
		if ( $lastChecked === null ) {
			// If $lastChecked is null, then only get rows with the last checked value as null.
			$selectQueryBuilder->where( [ 'mms_last_checked' => null ] );
		} else {
			// If $lastChecked is not null, then treat it as a timestamp.
			// Then using this timestamp as a date in the form YYYYMMDD, filter
			// for rows with a smaller last checked date or which have never been
			// checked (last checked as null).
			$lastCheckedAsMWTimestamp = $this->getDateFromTimestamp( $lastChecked );
			$selectQueryBuilder->where(
				$dbr->expr(
					'mms_last_checked',
					'<=',
					$lastCheckedAsMWTimestamp
				)->or( 'mms_last_checked', '=', null )
			);
		}
		if ( $dbr->getType() === 'postgres' ) {
			// Postgres DBs treat NULLs by default as larger than non-NULL values.
			// This is the opposite for Mariadb / SQLite. Postgres should have the same
			// behaviour as Mariadb / SQLite. By using NULLS FIRST and NULLS LAST
			// we can control where the NULL comes in the results list for postgres DBs
			if ( $direction === SelectQueryBuilder::SORT_ASC ) {
				$direction .= ' NULLS FIRST';
			} else {
				$direction .= ' NULLS LAST';
			}
		}
		// Filter by match status if $matchStatus does not indicate to
		// allow rows with any match status.
		if ( $matchStatus !== self::ANY_MATCH_STATUS ) {
			$selectQueryBuilder->where( [ 'mms_is_match' => $matchStatus ] );
		}
		// Exclude the SHA-1 values specified by the caller, if any are provided.
		if ( count( $excludedSha1Values ) ) {
			$selectQueryBuilder->where( $dbr->expr(
				'mms_sha1', '!=', $excludedSha1Values
			) );
		}
		// Return the constructed SelectQueryBuilder after adding the order by field.
		return $selectQueryBuilder
			->orderBy( 'mms_last_checked', $direction );
	}

	/**
	 * Gets $limit rows from the mediamoderation_scan table that have mms_last_checked less than the timestamp
	 * in $lastChecked. The returned rows are ordered by last checked timestamp in $direction.
	 *
	 * @param int $limit The maximum number of scan rows to return
	 * @param ConvertibleTimestamp|int|string|null $lastChecked Filters for scan rows that have been last checked
	 *   on or before this date. If null, only include files which have never been checked. If not null, treats this as
	 *   a timestamp that can be parsed by ConvertibleTimestamp.
	 * @param string $direction Either SelectQueryBuilder::SORT_ASC or ::SORT_DESC. Used to control
	 *    whether to start at the rows with the newest or oldest last checked timestamp. No-op if $lastChecked
	 *    is null.
	 * @param array $excludedSha1Values SHA-1 values to exclude from the returned array.
	 * @param string|null $matchStatus Filter for rows that have this match status. Any constants of this
	 *     service in the format ::*_MATCH_STATUS can be passed in this parameter. The default is to not filter
	 *     by match status (using ::ANY_MATCH_STATUS).
	 * @return array The SHA-1 values from the selected rows
	 * @throws TimestampException If the $lastChecked timestamp could not be parsed as a valid timestamp.
	 */
	public function getSha1ValuesForScan(
		int $limit, $lastChecked, string $direction,
		array $excludedSha1Values, ?string $matchStatus
	): array {
		// Return up to $limit SHA-1 values that match the given criteria.
		return $this->newSelectQueryBuilderForScan( $lastChecked, $direction, $excludedSha1Values, $matchStatus )
			->select( 'mms_sha1' )
			->limit( $limit )
			->fetchFieldValues();
	}
}
