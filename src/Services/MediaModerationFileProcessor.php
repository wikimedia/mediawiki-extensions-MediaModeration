<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use ArchivedFile;
use Error;
use File;
use MediaHandlerFactory;
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
	private MediaHandlerFactory $mediaHandlerFactory;
	private LoggerInterface $logger;

	public function __construct(
		MediaModerationDatabaseManager $mediaModerationDatabaseManager,
		MediaHandlerFactory $mediaHandlerFactory,
		LoggerInterface $logger
	) {
		$this->mediaModerationDatabaseManager = $mediaModerationDatabaseManager;
		$this->mediaHandlerFactory = $mediaHandlerFactory;
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
	 * @param File|ArchivedFile $file
	 * @return bool
	 */
	public function canScanFile( $file ): bool {
		$canScanFile = in_array( $file->getMimeType(), self::ALLOWED_MIME_TYPES, true );
		if ( $file instanceof File ) {
			$canScanFile = $canScanFile || $file->canRender();
		} elseif ( !$canScanFile ) {
			// Only attempt to check if the file can be rendered if the above
			// check failed as the ArchivedFile canRender check is complicated.
			$mediaHandler = $this->mediaHandlerFactory->getHandler( $file->getMimeType() );
			if ( $mediaHandler ) {
				try {
					// This suppression and passing of ArchivedFile to a MediaHandler method
					// which expects a File object is in the same way as ArchivedFile::pageCount.
					// TODO: Fix me if ArchivedFile ever extends File.
					// @phan-suppress-next-line PhanTypeMismatchArgument
					$fileCanBeRendered = $mediaHandler->canRender( $file );
				} catch ( Error $e ) {
					// All errors need to be caught, because a method not existing
					// will raise the generic in-built Error exception.
					// If the MediaHandler raises an exception for any reason the
					// result of this method will be false, and no further actions
					// would be taken for this file.
					$this->logger->error(
						'Call to MediaHandler::canRender with an ArchivedFile did not work for handler {handlerclass}',
						[
							'handlerclass' => get_class( $mediaHandler ),
							'exception' => $e
						]
					);
					$fileCanBeRendered = false;
				}
				// The ArchivedFile::exists check is done to make this similar to File::canRender.
				$canScanFile = $fileCanBeRendered && $file->exists();
			}
		}
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
	 * @param File|ArchivedFile $file
	 * @return void
	 */
	public function insertFile( $file ): void {
		if ( $this->canScanFile( $file ) ) {
			$this->mediaModerationDatabaseManager->insertFileToScanTable( $file );
		}
	}
}
