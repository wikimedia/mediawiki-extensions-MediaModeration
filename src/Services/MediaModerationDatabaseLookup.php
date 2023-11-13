<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use File;
use IDBAccessObject;
use Wikimedia\Rdbms\IConnectionProvider;

class MediaModerationDatabaseLookup implements IDBAccessObject {

	private IConnectionProvider $connectionProvider;

	public function __construct( IConnectionProvider $connectionProvider ) {
		$this->connectionProvider = $connectionProvider;
	}

	/**
	 * Returns whether the given $file exists in the mediamoderation_scan table.
	 *
	 * @param File $file
	 * @param int $flags IDBAccessObject flags. Does not support READ_LOCKING or READ_EXCLUSIVE
	 * @return bool
	 */
	public function fileExistsInScanTable( File $file, int $flags = self::READ_NORMAL ): bool {
		if ( $flags & self::READ_LATEST ) {
			$db = $this->connectionProvider->getPrimaryDatabase( 'virtual-mediamoderation' );
		} else {
			$db = $this->connectionProvider->getReplicaDatabase( 'virtual-mediamoderation' );
		}
		return (bool)$db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'mediamoderation_scan' )
			->where( [ 'mms_sha1' => $file->getSha1() ] )
			->caller( __METHOD__ )
			->fetchField();
	}
}
