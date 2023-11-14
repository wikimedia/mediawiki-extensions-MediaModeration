<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use File;
use Psr\Log\LoggerInterface;

class MediaModerationFileProcessor {
	private const ALLOWED_MIME_TYPES = [
		'image/gif',
		'image/jpeg',
		'image/png',
		'image/bmp',
		'image/tiff',
	];

	private MediaModerationDatabaseManager $mediaModerationDatabaseManager;
	private LoggerInterface $logger;

	public function __construct(
		MediaModerationDatabaseManager $mediaModerationDatabaseManager,
		LoggerInterface $logger
	) {
		$this->mediaModerationDatabaseManager = $mediaModerationDatabaseManager;
		$this->logger = $logger;
	}

	/**
	 * Returns whether a file can be scanned by PhotoDNA.
	 *
	 * This currently is limited to whether the file has
	 * a MIME type that is supported or can be rendered
	 * into a thumbnail of a supported MIME type.
	 *
	 * This is to be used to determine whether an image
	 * should be added to the scan table and should be
	 * used before attempting to scan the file.
	 *
	 * @param File $file
	 * @return bool
	 */
	public function canScanFile( File $file ): bool {
		$canScanFile = in_array( $file->getMimeType(), self::ALLOWED_MIME_TYPES, true ) ||
			$file->canRender();
		if ( !$canScanFile ) {
			$this->logger->debug(
				'File with SHA-1 {sha1} cannot be scanned by PhotoDNA',
				[ 'sha1' => $file->getSha1() ]
			);
		}
		return $canScanFile;
	}

	/**
	 * Should be called when a file has been created in the
	 * 'image' table, or when backfilling entries from the
	 * image, oldimage, and filearchive tables.
	 *
	 * @param File $file
	 * @return void
	 */
	public function insertFile( File $file ): void {
		if ( $this->canScanFile( $file ) ) {
			$this->mediaModerationDatabaseManager->insertFileToScanTable( $file );
		}
	}
}
