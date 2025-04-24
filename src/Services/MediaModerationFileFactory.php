<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use InvalidArgumentException;
use MediaWiki\FileRepo\File\ArchivedFile;
use MediaWiki\FileRepo\File\LocalFile;
use MediaWiki\FileRepo\LocalRepo;

/**
 * A service that allows creating File or ArchivedFile objects
 * for given rows from the image, oldimage, and filearchive tables.
 */
class MediaModerationFileFactory {

	private LocalRepo $localRepo;

	public function __construct( LocalRepo $localRepo ) {
		$this->localRepo = $localRepo;
	}

	/**
	 * Get the LocalFile or ArchiveFile object for the $row.
	 * The exact object type depends on the $table provided.
	 *
	 * @param \stdClass $row
	 * @param string $table
	 * @return ArchivedFile|LocalFile
	 */
	public function getFileObjectForRow( $row, string $table ) {
		if ( $table === 'image' || $table === 'oldimage' ) {
			return $this->localRepo->newFileFromRow( (object)$row );
		} elseif ( $table === 'filearchive' ) {
			return ArchivedFile::newFromRow( (object)$row );
		} else {
			throw new InvalidArgumentException( "Unrecognised image table '$table'." );
		}
	}
}
