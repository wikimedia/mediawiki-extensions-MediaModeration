<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use ArchivedFile;
use File;
use FileBackend;
use FormatJson;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaTransformError;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\Exception\RuntimeException;
use MediaWiki\Extension\MediaModeration\PhotoDNA\IMediaModerationPhotoDNAServiceProvider;
use MediaWiki\Extension\MediaModeration\PhotoDNA\MediaModerationPhotoDNAResponseHandler;
use MediaWiki\Extension\MediaModeration\PhotoDNA\Response;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\RawMessage;
use MimeAnalyzer;
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
	private StatsdDataFactoryInterface $perDbNameStatsdDataFactory;
	private MimeAnalyzer $mimeAnalyzer;
	private string $photoDNAUrl;
	private ?string $httpProxy;
	private string $photoDNASubscriptionKey;
	private int $thumbnailWidth;

	/**
	 * @param ServiceOptions $options
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param FileBackend $fileBackend
	 * @param StatsdDataFactoryInterface $perDbNameStatsdDataFactory
	 * @param MimeAnalyzer $mimeAnalyzer
	 */
	public function __construct(
		ServiceOptions $options,
		HttpRequestFactory $httpRequestFactory,
		FileBackend $fileBackend,
		StatsdDataFactoryInterface $perDbNameStatsdDataFactory,
		MimeAnalyzer $mimeAnalyzer
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->httpRequestFactory = $httpRequestFactory;
		$this->fileBackend = $fileBackend;
		$this->photoDNAUrl = $options->get( 'MediaModerationPhotoDNAUrl' );
		$this->photoDNASubscriptionKey = $options->get( 'MediaModerationPhotoDNASubscriptionKey' );
		$this->httpProxy = $options->get( 'MediaModerationHttpProxy' );
		$this->thumbnailWidth = $options->get( 'MediaModerationThumbnailWidth' );
		$this->perDbNameStatsdDataFactory = $perDbNameStatsdDataFactory;
		$this->mimeAnalyzer = $mimeAnalyzer;
	}

	/** @inheritDoc */
	public function check( $file ): StatusValue {
		try {
			$request = $this->getRequest( $file );
		} catch ( RuntimeException $exception ) {
			$this->perDbNameStatsdDataFactory->increment(
				'MediaModeration.PhotoDNAServiceProvider.Execute.RuntimeException'
			);
			return StatusValue::newFatal( new RawMessage( $exception->getMessage() ) );
		}
		$start = microtime( true );
		$status = $request->execute();
		$delay = microtime( true ) - $start;
		$this->perDbNameStatsdDataFactory->timing(
			'MediaModeration.PhotoDNAServiceProviderRequestTime',
			1000 * $delay
		);
		$statsdKey = $status->isOK() ? 'OK' : 'Error';
		$this->perDbNameStatsdDataFactory->increment(
			'MediaModeration.PhotoDNAServiceProvider.Execute.' . $statsdKey
		);
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
		$response = Response::newFromArray( $responseJson, $rawResponse );
		$this->perDbNameStatsdDataFactory->increment(
			'MediaModeration.PhotoDNAServiceProvider.Execute.StatusCode' . $response->getStatusCode()
		);
		return $this->createStatusFromResponse( $response );
	}

	/**
	 * @param File|ArchivedFile $file
	 * @throws RuntimeException
	 * @return MWHttpRequest
	 */
	private function getRequest( $file ): MWHttpRequest {
		$thumbnail = $this->getThumbnailForFile( $file );
		$thumbnailContents = $this->getThumbnailContents( $thumbnail );
		$options = [
			'method' => 'POST',
			'postData' => $thumbnailContents
		];
		if ( $this->httpProxy ) {
			$options['proxy'] = $this->httpProxy;
		}
		$request = $this->httpRequestFactory->create(
			$this->photoDNAUrl,
			$options
		);
		$request->setHeader( 'Content-Type', $this->getThumbnailMimeType( $thumbnail ) );
		$request->setHeader( 'Ocp-Apim-Subscription-Key', $this->photoDNASubscriptionKey );
		return $request;
	}

	/**
	 * Gets the mime type (or best guess for it) of the given $thumbnail.
	 *
	 * @param ThumbnailImage $thumbnail
	 * @return string
	 * @throws RuntimeException If the mime type could not be worked out or is not supported
	 *   by PhotoDNA.
	 */
	private function getThumbnailMimeType( ThumbnailImage $thumbnail ): string {
		// Attempt to work out what the mime type of the file is based on the extension, and if that
		// fails then try based on the contents of the thumbnail.
		$thumbnailMimeType = $this->mimeAnalyzer->getMimeTypeFromExtensionOrNull( $thumbnail->getExtension() );
		if ( $thumbnailMimeType === null ) {
			$thumbnailMimeType = $this->mimeAnalyzer->guessMimeType( $thumbnail->getLocalCopyPath() );
		}
		if ( !$thumbnailMimeType ) {
			// We cannot send a request to PhotoDNA without knowing what the mime type is.
			throw new RuntimeException(
				'Could not get mime type of thumbnail for ' . $thumbnail->getFile()->getName()
			);
		}
		if ( !in_array( $thumbnailMimeType, MediaModerationFileProcessor::ALLOWED_MIME_TYPES, true ) ) {
			// We cannot send a request to PhotoDNA with a thumbnail type that is unsupported by the API.
			throw new RuntimeException(
				'Mime type of thumbnail for ' . $thumbnail->getFile()->getName() . ' is not supported by PhotoDNA.'
			);
		}
		return $thumbnailMimeType;
	}

	/**
	 * @param File|ArchivedFile $file
	 * @return ThumbnailImage
	 */
	private function getThumbnailForFile( $file ): ThumbnailImage {
		$genericErrorMessage = 'Could not transform file ' . $file->getName();
		if ( $file instanceof ArchivedFile ) {
			// ArchivedFile is not supported yet, so return that no thumbnail can be generated
			throw new RuntimeException( $genericErrorMessage . ': ArchivedFile instances cannot be processed yet.' );
		}
		$start = microtime( true );
		$thumbnail = $file->transform( [ 'width' => $this->thumbnailWidth ], File::RENDER_NOW );
		$delay = microtime( true ) - $start;
		$this->perDbNameStatsdDataFactory->timing(
			'MediaModeration.PhotoDNAServiceProviderThumbnailTransform',
			1000 * $delay
		);
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
		if ( !$thumbnail->getStoragePath() ) {
			throw new RuntimeException(
				'Could not get storage path of thumbnail for ' . $thumbnail->getFile()->getName()
			);
		}
		$fileContents = $this->fileBackend->getFileContents( [ 'src' => $thumbnail->getStoragePath() ] );
		if ( !$fileContents ) {
			throw new RuntimeException(
				'Could not get thumbnail contents for ' . $thumbnail->getFile()->getName()
			);
		}
		return $fileContents;
	}
}
