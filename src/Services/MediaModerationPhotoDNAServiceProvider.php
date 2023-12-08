<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use ArchivedFile;
use File;
use FileBackend;
use FormatJson;
use MediaTransformError;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\Exception\RuntimeException;
use MediaWiki\Extension\MediaModeration\PhotoDNA\IMediaModerationPhotoDNAServiceProvider;
use MediaWiki\Extension\MediaModeration\PhotoDNA\MediaModerationPhotoDNAResponseHandler;
use MediaWiki\Extension\MediaModeration\PhotoDNA\Response;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\RawMessage;
use MWHttpRequest;
use StatusValue;
use ThumbnailImage;

/**
 * Service for interacting with Microsoft PhotoDNA API.
 *
 * @see https://developer.microsoftmoderator.com/docs/services/57c7426e2703740ec4c9f4c3/operations/57c7426f27037407c8cc69e6
 */
class MediaModerationPhotoDNAServiceProvider implements IMediaModerationPhotoDNAServiceProvider {

	use MediaModerationPhotoDNAResponseHandler;

	public const CONSTRUCTOR_OPTIONS = [
		'MediaModerationPhotoDNAUrl',
		'MediaModerationPhotoDNASubscriptionKey',
		'MediaModerationHttpProxy',
		'MediaModerationThumbnailWidth'
	];

	private HttpRequestFactory $httpRequestFactory;
	private FileBackend $fileBackend;
	private string $photoDNAUrl;
	private ?string $httpProxy;
	private string $photoDNASubscriptionKey;
	private int $thumbnailWidth;

	/**
	 * @param ServiceOptions $options
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param FileBackend $fileBackend
	 */
	public function __construct(
		ServiceOptions $options,
		HttpRequestFactory $httpRequestFactory,
		FileBackend $fileBackend
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->httpRequestFactory = $httpRequestFactory;
		$this->fileBackend = $fileBackend;
		$this->photoDNAUrl = $options->get( 'MediaModerationPhotoDNAUrl' );
		$this->photoDNASubscriptionKey = $options->get( 'MediaModerationPhotoDNASubscriptionKey' );
		$this->httpProxy = $options->get( 'MediaModerationHttpProxy' );
		$this->thumbnailWidth = $options->get( 'MediaModerationThumbnailWidth' );
	}

	/** @inheritDoc */
	public function check( $file ): StatusValue {
		try {
			$request = $this->getRequest( $file );
		} catch ( RuntimeException $exception ) {
			return StatusValue::newFatal(
				new RawMessage(
					'Unable to get file contents for file ' . $file->getName()
				)
			);
		}
		$status = $request->execute();
		if ( !$status->isOK() ) {
			// Something went badly wrong.
			$errorMessage = FormatJson::decode( $request->getContent(), true );
			if ( is_array( $errorMessage ) && isset( $errorMessage['message'] ) ) {
				$errorMessage = $errorMessage['message'];
			} else {
				$errorMessage = 'Unable to get JSON in response from PhotoDNA';
			}

			return StatusValue::newFatal(
				new RawMessage(
					'PhotoDNA returned HTTP ' . $request->getStatus() . ' error: ' . $errorMessage
				)
			);
		}
		$rawResponse = $request->getContent();
		$responseJson = FormatJson::parse( $rawResponse, FormatJson::FORCE_ASSOC )->getValue();
		return $this->createStatusFromResponse(
			Response::newFromArray( $responseJson, $rawResponse )
		);
	}

	/**
	 * @param File|ArchivedFile $file
	 * @throws RuntimeException
	 * @return MWHttpRequest
	 */
	private function getRequest( $file ): MWHttpRequest {
		$thumbnail = $this->getThumbnailForFile( $file );
		$options = [
			'method' => 'POST',
			'postData' => $this->getThumbnailContents( $thumbnail )
		];
		if ( $this->httpProxy ) {
			$options['proxy'] = $this->httpProxy;
		}
		$request = $this->httpRequestFactory->create(
			$this->photoDNAUrl,
			$options
		);
		$request->setHeader( 'Content-Type', $thumbnail->getFile()->getMimeType() );
		$request->setHeader( 'Ocp-Apim-Subscription-Key', $this->photoDNASubscriptionKey );
		return $request;
	}

	/**
	 * @param File|ArchivedFile $file
	 * @return ThumbnailImage
	 */
	private function getThumbnailForFile( $file ): ThumbnailImage {
		$thumbnail = $file->transform( [ 'width' => $this->thumbnailWidth ], File::RENDER_NOW );
		$genericErrorMessage = 'Could not transform file ' . $file->getName();
		if ( !$thumbnail ) {
			throw new RuntimeException( $genericErrorMessage );
		}
		if ( $thumbnail instanceof MediaTransformError ) {
			throw new RuntimeException( $genericErrorMessage . ': ' . $thumbnail->toText() );
		}
		if ( !( $thumbnail instanceof ThumbnailImage ) ) {
			throw new RuntimeException( $genericErrorMessage .
				': not an instance of ThumbnailImage, got ' . get_class( $thumbnail )
			);
		}
		if ( !$thumbnail->hasFile() ) {
			throw new RuntimeException(
				$genericErrorMessage .
				', got a ' . get_class( $thumbnail ) . ' but ::hasFile() returns false.'
			);
		}
		return $thumbnail;
	}

	private function getThumbnailContents( ThumbnailImage $thumbnail ): string {
		$fileContents = $this->fileBackend->getFileContents( [ 'src' => $thumbnail->getStoragePath() ] );
		if ( !$fileContents ) {
			throw new RuntimeException( 'Could not get file contents for ' . $thumbnail->getFile()->getName() );
		}
		return $fileContents;
	}
}
