<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use Error;
use MediaHandlerFactory;
use MediaWiki\FileRepo\File\ArchivedFile;
use MediaWiki\FileRepo\File\File;
use Psr\Log\LoggerInterface;

class MediaModerationFileProcessor {
	/** @var string[] An array of mime types that are supported by PhotoDNA. */
	public const ALLOWED_MIME_TYPES = [
		'image/gif',
		'image/jpeg',
		'image/png',
		'image/bmp',
		'image/tiff',
	];

	/** @var array An array of mediatypes that can be properly converted to an accepted mime type for PhotoDNA. */
	private const ALLOWED_MEDIA_TYPES = [
		MEDIATYPE_BITMAP,
		MEDIATYPE_DRAWING,
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
	 * Returns whether a file has an allowed media type.
	 * This check is needed because some files may be
	 * renderable but not in a supported format (T352234).
	 *
	 * @param File|ArchivedFile $file
	 * @return bool
	 */
	private function fileHasAllowedMediaType( $file ): bool {
		return in_array( $file->getMediaType(), self::ALLOWED_MEDIA_TYPES, true );
	}

	/**
	 * Returns whether a file has an allowed mime type
	 * and therefore could be sent directly to PhotoDNA
	 * without having to convert the file type.
	 *
	 * @param File|ArchivedFile $file
	 * @return bool
	 */
	private function fileHasAllowedMimeType( $file ): bool {
		return in_array( $file->getMimeType(), self::ALLOWED_MIME_TYPES, true );
	}

	/**
	 * Returns whether a file can be likely rendered,
	 * which is the result of File::canRender. The behaviour
	 * is similar for ArchivedFile objects.
	 *
	 * @param File|ArchivedFile $file
	 * @return bool
	 */
	private function fileCanBeRendered( $file ): bool {
		if ( $file instanceof File ) {
			return $file->canRender();
		}
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
					'Call to MediaHandler::canRender with an ArchivedFile did not work ' .
					'for handler {handlerclass}',
					[
						'handlerclass' => get_class( $mediaHandler ),
						'exception' => $e
					]
				);
				return false;
			}
			// The ArchivedFile::exists check is done to make this similar to File::canRender.
			return $fileCanBeRendered && $file->exists();
		}
		return false;
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
		$canScanFile = $this->fileHasAllowedMediaType( $file ) &&
			(
				$this->fileHasAllowedMimeType( $file ) ||
				$this->fileCanBeRendered( $file )
			);
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
