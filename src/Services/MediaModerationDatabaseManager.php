<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use ArchivedFile;
use File;
use IDBAccessObject;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class MediaModerationDatabaseManager implements IDBAccessObject {

	private IDatabase $dbw;
	private MediaModerationDatabaseLookup $mediaModerationDatabaseLookup;

	public function __construct(
		IDatabase $dbw,
		MediaModerationDatabaseLookup $mediaModerationDatabaseLookup
	) {
		$this->dbw = $dbw;
		$this->mediaModerationDatabaseLookup = $mediaModerationDatabaseLookup;
	}

	/**
	 * Takes a File object and adds a reference to the file in the
	 * mediamoderation_scan table if the reference to the file does
	 * not already exist.
	 *
	 * @param File|ArchivedFile $file The file to be added to the mediamoderation_scan table.
	 * @return void
	 */
	public function insertFileToScanTable( $file ) {
		if ( !$this->mediaModerationDatabaseLookup->fileExistsInScanTable(
			$file, MediaModerationDatabaseLookup::READ_LATEST
		) ) {
			$this->insertToScanTableInternal( $file );
		}
	}

	/**
	 * Inserts a given File to the mediamoderation_scan table.
	 * Does not check for the existence of the File in the scan
	 * table, so will cause a DBError if the File is already in
	 * the table.
	 *
	 * @param File|ArchivedFile $file
	 * @return void
	 */
	private function insertToScanTableInternal( $file ) {
		// Insert a row to the mediamoderation_scan table with the SHA-1 of the file.
		$this->dbw->newInsertQueryBuilder()
			->insert( 'mediamoderation_scan' )
			->row( [ 'mms_sha1' => $file->getSha1() ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Updates the mediamoderation_scan row with for the given file
	 * with the match status as determined by PhotoDNA.
	 *
	 * This also sets the mss_last_checked column to the current time
	 * to indicate that now is the last time the file was checked.
	 *
	 * If the SHA-1 of the $file does not exist in the scan table, a row
	 * will be created for it before the update occurs.
	 *
	 * @param File|ArchivedFile $file The file that was scanned by PhotoDNA
	 * @param null|bool $isMatch Whether the file is a match (null if the scan failed)
	 * @return void
	 */
	public function updateMatchStatus( $file, ?bool $isMatch ) {
		// Check if the SHA-1 exists in the scan table. If not, then add it to the
		// mediamoderation_scan table first before attempting to update the match status.
		$this->insertFileToScanTable( $file );
		$this->updateMatchStatusForSha1( $file->getSha1(), $isMatch );
	}

	/**
	 * Updates the mediamoderation_scan row for the given SHA-1
	 * with the match status as determined by PhotoDNA.
	 *
	 * If you have a $file object, you should pass use ::updateMatchStatus
	 * instead as this method does not first check if the file is referenced
	 * in the mediamoderation_scan table.
	 *
	 * @param string $sha1
	 * @param null|bool $isMatch Whether the file is a match (null if the scan failed)
	 * @return void
	 */
	public function updateMatchStatusForSha1( string $sha1, ?bool $isMatch ) {
		// If $isMatch is a boolean, convert this to the string representation of a boolean
		// for storage in the DB.
		if ( $isMatch !== null ) {
			$isMatch = intval( $isMatch );
		}
		// Update the match status for the $sha1 and also update the last checked timestamp.
		$this->dbw->newUpdateQueryBuilder()
			->table( 'mediamoderation_scan' )
			->set( [
				'mms_is_match' => $isMatch,
				// Take the MW_TS current timestamp and keep the first 8 characters which is YYYYMMDD.
				'mms_last_checked' => intval( substr( ConvertibleTimestamp::now(), 0, 8 ) )
			] )
			->where( [ 'mms_sha1' => $sha1 ] )
			->caller( __METHOD__ )
			->execute();
	}
}
