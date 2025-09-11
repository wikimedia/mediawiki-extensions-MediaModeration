<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use MediaTransformError;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\Media\ThumborThumbnailImage;
use MediaWiki\Extension\MediaModeration\Status\ImageContentsLookupStatus;
use MediaWiki\FileRepo\File\ArchivedFile;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\RawMessage;
use MediaWiki\MainConfigNames;
use MediaWiki\WikiMap\WikiMap;
use StatusValue;
use ThumbnailImage;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\Stats\StatsFactory;

/**
 * This service looks up the contents of the given $file, either by getting a thumbnail
 * with width as specified in the wgMediaModerationThumbnailWidth config or if this fails
 * using the original source file.
 */
class MediaModerationImageContentsLookup {

	public const CONSTRUCTOR_OPTIONS = [
		MainConfigNames::ThumbnailSteps,
		'MediaModerationThumbnailWidth',
		'MediaModerationThumbnailMinimumSize',
		'MediaModerationThumborRequestTimeout',
	];

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly FileBackend $fileBackend,
		private readonly StatsFactory $statsFactory,
		private readonly MimeAnalyzer $mimeAnalyzer,
		private readonly LocalRepo $localRepo,
		private readonly HttpRequestFactory $httpRequestFactory,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Gets the image contents as a string for the given $file to be sent to PhotoDNA.
	 *
	 * This function first tries to get a thumbnail for the $file and return the contents of the
	 * thumbnail. If this fails, then the function tries to return the contents of the $file
	 * if the $file is in a format supported by PhotoDNA.
	 *
	 * @param File|ArchivedFile $file
	 * @return ImageContentsLookupStatus
	 */
	public function getImageContents( $file ): ImageContentsLookupStatus {
		$wiki = WikiMap::getCurrentWikiId();
		// Create a status that will be returned, and if it is good will contain the
		// thumbnail/original file contents and mime type.
		$returnStatus = new ImageContentsLookupStatus();
		if ( $file instanceof File ) {
			// Only try to use the thumbnail if the $file is an instance of the File class,
			// as support for generating a thumbnail for deleted files is not implemented.
			$thumbnailStatus = $this->getThumbnailForFile( $file );
			if ( $thumbnailStatus->isGood() ) {
				// If we could get the ThumbnailImage object for the $file, then
				// try to get the contents of the thumbnail along with the mime type.
				$thumbnail = $thumbnailStatus->getValue();
				$thumbnailContentsStatus = $this->getThumbnailContents( $thumbnail );
				$thumbnailMimeTypeStatus = $this->getThumbnailMimeType( $thumbnail );
				if ( $thumbnailContentsStatus->isGood() && $thumbnailMimeTypeStatus->isGood() ) {
					// If we were able to get the thumbnail contents and mime type, then return with them.
					return $returnStatus
						->setImageContents( $thumbnailContentsStatus->getValue() )
						->setMimeType( $file->getMimeType() );
				}
				// Add the failures to the return status for the caller to see.
				$returnStatus
					->merge( $thumbnailMimeTypeStatus )
					->merge( $thumbnailContentsStatus );
			}
			// Add the failures to the return status for the caller to see.
			$returnStatus->merge( $thumbnailStatus );
		}
		// If no thumbnail could be generated for the $file or the $file is an ArchivedFile instance, then we will
		// reach here. Now try to get the contents of the $file if the mime type type is supported by PhotoDNA.
		if ( in_array( $file->getMimeType(), MediaModerationFileProcessor::ALLOWED_MIME_TYPES, true ) ) {
			$fileContentsStatus = $this->getFileContents( $file );
			if ( $fileContentsStatus->isGood() ) {
				// We were able to get the contents of the $file
				if ( $file instanceof File ) {
					// Add to the RuntimeException count if $file was a File, as we should
					// have been able to generate a thumbnail for it.
					$this->statsFactory->withComponent( 'MediaModeration' )
						->getCounter( 'image_contents_lookup_used_source_file_total' )
						->setLabel( 'wiki', $wiki )
						->copyToStatsdAt(
							"$wiki.MediaModeration.PhotoDNAServiceProvider.Execute.SourceFileUsedForFileObject"
						)
						->increment();
				}
				// Set the result as OK as we got the original file, but still include the
				// errors from the thumbnail generation for tracking.
				return $returnStatus
					->setOK( true )
					->setImageContents( $fileContentsStatus->getValue() )
					->setMimeType( $file->getMimeType() );
			}
			// If we were unable to get the contents of the $file, then add the errors from
			// this to the return status.
			$returnStatus->merge( $fileContentsStatus );
		}
		// If we get here, then we have failed to get any image contents and so should return a fatal status.
		if ( $returnStatus->isOK() ) {
			// The $returnStatus can be good and have no message if the image was deleted and the source image is
			// not supported by PhotoDNA (such as a deleted SVG).
			$returnStatus->fatal( new RawMessage( "Failed to get image contents for {$file->getName()}" ) );
		}
		// Increment the RuntimeException statsd counter, as we have reached a point where
		// we could not generate a thumbnail where we should have been able to.
		$this->statsFactory->withComponent( 'MediaModeration' )
			->getCounter( 'image_contents_lookup_failure_total' )
			->setLabel( 'wiki', $wiki )
			->copyToStatsdAt( "$wiki.MediaModeration.PhotoDNAServiceProvider.Execute.RuntimeException" )
			->increment();
		return $returnStatus;
	}

	/**
	 * Gets the mime type (or best guess for it) of the given $thumbnail.
	 *
	 * @param ThumbnailImage $thumbnail
	 * @return StatusValue
	 */
	protected function getThumbnailMimeType( ThumbnailImage $thumbnail ): StatusValue {
		// Attempt to work out what the mime type of the file is based on the extension, and if that
		// fails then try based on the contents of the thumbnail.
		$thumbnailMimeType = $thumbnail instanceof ThumborThumbnailImage ?
			$thumbnail->getContentType() :
			$this->mimeAnalyzer->getMimeTypeFromExtensionOrNull( $thumbnail->getExtension() );
		if ( $thumbnailMimeType === null ) {
			$thumbnailMimeType = $this->mimeAnalyzer->guessMimeType( $thumbnail->getLocalCopyPath() );
		}
		if ( !$thumbnailMimeType ) {
			// We cannot send a request to PhotoDNA without knowing what the mime type is.
			$this->incrementImageContentsLookupErrorTotal(
				'thumbnail', 'mime', 'lookup_failed',
				'MediaModeration.ImageContentsLookup.Thumbnail.MimeType.LookupFailed'
			);
			return StatusValue::newFatal( new RawMessage(
				"Could not get mime type of thumbnail for {$thumbnail->getFile()->getName()}"
			) );
		}
		if ( !in_array( $thumbnailMimeType, MediaModerationFileProcessor::ALLOWED_MIME_TYPES, true ) ) {
			// We cannot send a request to PhotoDNA with a thumbnail type that is unsupported by the API.
			$this->incrementImageContentsLookupErrorTotal(
				'thumbnail', 'mime', 'unsupported',
				'MediaModeration.ImageContentsLookup.Thumbnail.MimeType.Unsupported'
			);
			return StatusValue::newFatal( new RawMessage(
				"Mime type of thumbnail for {$thumbnail->getFile()->getName()} is not supported by PhotoDNA."
			) );
		}
		return StatusValue::newGood( $thumbnailMimeType );
	}

	/**
	 * Get the thumbnail width to be used that meets the dimension requirements in
	 * $wgMediaModerationThumbnailMinimumSize.
	 *
	 * Priority is given first to checking if the widths in $wgMediaModerationThumbnailWidth or
	 * $wgThumbnailSteps would meet the requirements. If none of these widths work, then a
	 * width is generated by getting the smallest possible width while meeting the dimension
	 * requirements.
	 *
	 * @param File $file
	 * @return int
	 */
	private function getThumbnailWidth( File $file ): int {
		$thumbnailWidth = $this->options->get( 'MediaModerationThumbnailWidth' );

		// Without a file width or height, we cannot calculate the optimal width. Therefore just return
		// $wgMediaModerationThumbnailWidth as a default.
		if ( !$file->getWidth() || !$file->getHeight() ) {
			return $thumbnailWidth;
		}

		// See if $wgMediaModerationThumbnailWidth would produce a thumbnail which meets the minimum height and width
		// requirements as listed in $wgMediaModerationThumbnailMinimumSize, returning it if it does.
		if ( $thumbnailWidth && $this->shouldThumbnailWidthMeetMinimumRequirements( $file, $thumbnailWidth ) ) {
			return $thumbnailWidth;
		}

		// Next try the same check but on any thumbnail widths listed in ThumbnailSteps to avoid generating too
		// many thumbnails, as these thumbnail widths should be defined.
		$thumbnailSteps = $this->options->get( MainConfigNames::ThumbnailSteps );
		if ( $thumbnailSteps ) {
			sort( $thumbnailSteps );
			foreach ( $thumbnailSteps as $step ) {
				if ( $this->shouldThumbnailWidthMeetMinimumRequirements( $file, $step ) ) {
					return $step;
				}
			}
		}

		// As the pre-defined sizes have been tried and did not meet the requirements, we should try to pick a width
		// by working out the minimum width needed to get a file that is tall enough.
		$minThumbnailSize = $this->options->get( 'MediaModerationThumbnailMinimumSize' );
		$minWidthScalingFactor = $minThumbnailSize['width'] / $file->getWidth();
		$minHeightScalingFactor = $minThumbnailSize['height'] / $file->getHeight();
		$scalingFactor = max( $minHeightScalingFactor, $minWidthScalingFactor );

		return ceil( $file->getWidth() * $scalingFactor );
	}

	/**
	 * Works out if for a given thumbnail width and {@link File}, the resulting thumbnail
	 * should meet the minimum requirements for height and width.
	 *
	 * @param File $file
	 * @param int $thumbnailWidth
	 * @return bool
	 */
	private function shouldThumbnailWidthMeetMinimumRequirements( File $file, int $thumbnailWidth ): bool {
		$minThumbnailSize = $this->options->get( 'MediaModerationThumbnailMinimumSize' );
		if ( $thumbnailWidth < $minThumbnailSize['width'] ) {
			return false;
		}

		// We can assume that the scaling would be equal for both width and height. Therefore, work out the scaling
		// factor for the width and then apply that to the file height to get the expected thumbnail height.
		$scalingFactor = $thumbnailWidth / $file->getWidth();
		return $file->getHeight() * $scalingFactor >= $minThumbnailSize['height'];
	}

	/**
	 * @param File $file
	 * @return StatusValue<ThumbnailImage|ThumborThumbnailImage> A StatusValue with a ThumbnailImage object as the value
	 *   if it is a good status.
	 */
	protected function getThumbnailForFile( File $file ): StatusValue {
		$wiki = WikiMap::getCurrentWikiId();
		$genericErrorMessage = 'Could not transform file ' . $file->getName();

		// Attempt to get a thumbnail for the $file
		$start = microtime( true );
		$thumbnailWidth = $this->getThumbnailWidth( $file );
		$thumbProxyUrl = $file->getRepo()->getThumbProxyUrl();
		$secret = $file->getRepo()->getThumbProxySecret();
		if ( $thumbProxyUrl && $secret ) {
			$thumbName = $file->thumbName( [ 'width' => $thumbnailWidth ] );
			// Specific to Wikimedia setup only: proxy the request to Thumbor,
			// which should result in the thumbnail being generated on disk
			// @see wfProxyThumbnailRequest()
			$req = $this->httpRequestFactory->create(
				$thumbProxyUrl . $file->getThumbRel( $thumbName ),
				[
					'timeout' => $this->options->get( 'MediaModerationThumborRequestTimeout' )
				],
				__METHOD__
			);
			$req->setHeader( 'X-Swift-Secret', $secret );
			$result = $req->execute();
			// Log the HTTP status code from Thumbor (T385448)
			$this->statsFactory->withComponent( 'MediaModeration' )
				->getCounter( 'image_contents_lookup_thumbor_request_total' )
				->setLabel( 'wiki', $wiki )
				->setLabel( 'status_code', strval( $req->getStatus() ) )
				->increment();
			if ( $result->isGood() ) {
				$imageContent = $req->getContent();
				// getimagesizefromstring() can return a PHP Notice if
				// the contents are invalid. Suppress the notice, and check
				// instead of the result is truthy.
				// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
				$imageMetadata = @getimagesizefromstring( $imageContent );
				if ( $imageMetadata ) {
					$thumbnail = new ThumborThumbnailImage(
						$file,
						$file->getThumbUrl( $thumbName ),
						[
							'width' => $imageMetadata[0],
							'height' => $imageMetadata[1]
						],
						$imageContent,
						$req->getResponseHeader( 'content-type' )
					);
					$this->statsFactory->withComponent( 'MediaModeration' )
						->getTiming( 'image_contents_lookup_thumbnail_transform_time' )
						->setLabel( 'wiki', $wiki )
						->setLabel( 'method', 'thumbor' )
						->copyToStatsdAt(
							"$wiki.MediaModeration.PhotoDNAServiceProviderThumbnailTransformThumborRequest"
						)
						->observeSeconds( microtime( true ) - $start );
					return StatusValue::newGood( $thumbnail );
				}
			}
			// The request failed, so increment the failure counter and use regular ::transform
			// for checks done further on.
			$this->incrementImageContentsLookupErrorTotal(
				'thumbnail', 'thumbor_transform', 'failed',
				'MediaModeration.ImageContentsLookup.Thumbnail.ThumborTransform.Failed'
			);
			$thumbnail = $file->transform( [ 'width' => $thumbnailWidth ] );
		} else {
			// For non Wikimedia setups, use RENDER_NOW to ensure we have
			// a file to work with.
			$thumbnail = $file->transform( [ 'width' => $thumbnailWidth ], File::RENDER_NOW );
		}
		$delay = microtime( true ) - $start;
		$this->statsFactory->withComponent( 'MediaModeration' )
			->getTiming( 'image_contents_lookup_thumbnail_transform_time' )
			->setLabel( 'wiki', $wiki )
			->setLabel( 'method', 'php' )
			->copyToStatsdAt( "$wiki.MediaModeration.PhotoDNAServiceProviderThumbnailTransform" )
			->observeSeconds( $delay );

		// Check if the $thumbnail is valid, returning a good status if this is the case.
		$returnStatus = StatusValue::newGood();
		if ( !$thumbnail ) {
			$returnStatus->fatal( new RawMessage( $genericErrorMessage ) );
		} elseif ( $thumbnail instanceof MediaTransformError ) {
			$returnStatus->fatal( new RawMessage( $genericErrorMessage . ': ' . $thumbnail->toText() ) );
		} elseif ( !( $thumbnail instanceof ThumbnailImage ) ) {
			$returnStatus->fatal( new RawMessage(
				$genericErrorMessage . ': not an instance of ThumbnailImage, got ' . get_class( $thumbnail )
			) );
		} elseif ( !$thumbnail->hasFile() ) {
			$returnStatus->fatal( new RawMessage(
				$genericErrorMessage . ', got a ' . get_class( $thumbnail ) . ' but ::hasFile() returns false.'
			) );
		} elseif ( !$thumbnail->getLocalCopyPath() ) {
			$returnStatus->fatal( new RawMessage(
				$genericErrorMessage . ', got a ' . get_class( $thumbnail ) . ' but ::getLocalCopyPath returns false.'
			) );
		} else {
			$returnStatus = $returnStatus->setResult( true, $thumbnail );
		}

		if ( !$returnStatus->isGood() ) {
			$this->incrementImageContentsLookupErrorTotal(
				'thumbnail', 'transform', 'failed',
				'MediaModeration.ImageContentsLookup.Thumbnail.Transform.Failed'
			);
		}
		return $returnStatus;
	}

	protected function getThumbnailContents( ThumbnailImage $thumbnail ): StatusValue {
		$minThumbnailSize = $this->options->get( 'MediaModerationThumbnailMinimumSize' );
		if (
			$thumbnail->getHeight() < $minThumbnailSize['height'] ||
			$thumbnail->getWidth() < $minThumbnailSize['width']
		) {
			$this->incrementImageContentsLookupErrorTotal(
				'thumbnail', 'contents', 'too_small',
				'MediaModeration.ImageContentsLookup.Thumbnail.Contents.TooSmall'
			);
			// PhotoDNA requires that images be at least 160px by 160px, so don't use the
			// thumbnail if either dimension is too small.
			return StatusValue::newFatal( new RawMessage(
				"Thumbnail does not meet dimension requirements for {$thumbnail->getFile()->getName()}"
			) );
		}
		if ( !( $thumbnail instanceof ThumborThumbnailImage ) && !$thumbnail->getStoragePath() ) {
			$this->incrementImageContentsLookupErrorTotal(
				'thumbnail', 'contents', 'lookup_failed',
				'MediaModeration.ImageContentsLookup.Thumbnail.Contents.LookupFailed'
			);
			return StatusValue::newFatal( new RawMessage(
				"Could not get storage path of thumbnail for {$thumbnail->getFile()->getName()}"
			) );
		}
		$fileContents = $thumbnail instanceof ThumborThumbnailImage ?
			$thumbnail->getContent() :
			$this->fileBackend->getFileContents( [ 'src' => $thumbnail->getStoragePath() ] );
		if ( !$fileContents ) {
			$this->incrementImageContentsLookupErrorTotal(
				'thumbnail', 'contents', 'lookup_failed',
				'MediaModeration.ImageContentsLookup.Thumbnail.Contents.LookupFailed'
			);
			return StatusValue::newFatal( new RawMessage(
				"Could not get thumbnail contents for {$thumbnail->getFile()->getName()}"
			) );
		}
		if ( strlen( $fileContents ) > 4000000 ) {
			$this->incrementImageContentsLookupErrorTotal(
				'thumbnail', 'contents', 'too_large',
				'MediaModeration.ImageContentsLookup.Thumbnail.Contents.TooLarge'
			);
			// Check that the size of the file does not exceed 4MB, as PhotoDNA returns an
			// error for files that are any larger.
			// strlen returns the size of the string in bytes and 4MB is 4,000,000 bytes.
			return StatusValue::newFatal( new RawMessage(
				"Original file contents exceeds 4MB for {$thumbnail->getFile()->getName()}"
			) );
		}
		return StatusValue::newGood( $fileContents );
	}

	/**
	 * Gets the contents of the originally uploaded file referenced by $file.
	 *
	 * @param File|ArchivedFile $file
	 * @return StatusValue
	 */
	protected function getFileContents( $file ): StatusValue {
		if ( $file->getSize() && $file->getSize() > 4000000 ) {
			$this->incrementImageContentsLookupErrorTotal(
				'source_file', 'contents', 'too_large',
				'MediaModeration.ImageContentsLookup.File.Contents.TooLarge'
			);
			// Check that the size of the file does not exceed 4MB, as PhotoDNA returns an
			// error for files that are any larger.
			return StatusValue::newFatal( new RawMessage(
				"Original file contents exceeds 4MB for {$file->getName()}"
			) );
		}
		if (
			( $file->getHeight() && $file->getHeight() < 160 ) ||
			( $file->getWidth() && $file->getWidth() < 160 )
		) {
			$this->incrementImageContentsLookupErrorTotal(
				'source_file', 'contents', 'too_small',
				'MediaModeration.ImageContentsLookup.File.Contents.TooSmall'
			);
			// Check that the height and width is at least 160px by 160px
			// as PhotoDNA requires that the file be at least that size.
			// If the height or width is false, then just ignore this check
			// as PhotoDNA will verify this for us.
			return StatusValue::newFatal( new RawMessage(
				"Original file does not meet dimension requirements for {$file->getName()}"
			) );
		}
		if ( $file instanceof ArchivedFile ) {
			// Format for the URL is copied from SpecialUndelete::showFile
			$filePath = $this->localRepo->getZonePath( 'deleted' ) . '/' .
				$this->localRepo->getDeletedHashPath( $file->getStorageKey() ) . $file->getStorageKey();
		} else {
			$filePath = $file->getPath();
		}
		if ( !$filePath ) {
			$this->incrementImageContentsLookupErrorTotal(
				'source_file', 'contents', 'lookup_failed',
				'MediaModeration.ImageContentsLookup.File.Contents.LookupFailed'
			);
			return StatusValue::newFatal( new RawMessage(
				"Could not get storage path of original file for {$file->getName()}"
			) );
		}
		$fileContents = $this->fileBackend->getFileContents( [ 'src' => $filePath ] );
		if ( !$fileContents ) {
			$this->incrementImageContentsLookupErrorTotal(
				'source_file', 'contents', 'lookup_failed',
				'MediaModeration.ImageContentsLookup.File.Contents.LookupFailed'
			);
			return StatusValue::newFatal( new RawMessage(
				"Could not get original file contents for {$file->getName()}"
			) );
		}
		return StatusValue::newGood( $fileContents );
	}

	/**
	 * Increments the 'image_contents_lookup_error_total' Prometheus metric with the given label values
	 * to describe the error.
	 *
	 * @param string $imageType Either 'thumbnail' or 'source_file'
	 * @param string $errorType The type of error. Used to group errors into a common group, such as all errors related
	 *   to looking up the contents of an image being 'contents'
	 * @param string $error The error that occurred (for example 'lookup_failed')
	 * @param string $statsDBucket Used to copy data to the old StatsD metric
	 */
	private function incrementImageContentsLookupErrorTotal(
		string $imageType, string $errorType, string $error, string $statsDBucket
	): void {
		$wiki = WikiMap::getCurrentWikiId();
		$this->statsFactory->withComponent( 'MediaModeration' )
			->getCounter( 'image_contents_lookup_error_total' )
			->setLabel( 'wiki', $wiki )
			->setLabel( 'image_type', $imageType )
			->setLabel( 'error_type', $errorType )
			->setLabel( 'error', $error )
			->copyToStatsdAt( "$wiki.$statsDBucket" )
			->increment();
	}
}
