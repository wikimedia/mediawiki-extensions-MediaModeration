<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use ArchivedFile;
use File;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\PhotoDNA\IMediaModerationPhotoDNAServiceProvider;
use MediaWiki\Extension\MediaModeration\PhotoDNA\MediaModerationPhotoDNAResponseHandler;
use MediaWiki\Extension\MediaModeration\PhotoDNA\Response;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\Language\RawMessage;
use MediaWiki\Status\StatusFormatter;
use MWHttpRequest;
use StatusValue;

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
	];

	private HttpRequestFactory $httpRequestFactory;
	private StatsdDataFactoryInterface $perDbNameStatsdDataFactory;
	private MediaModerationImageContentsLookup $mediaModerationImageContentsLookup;
	private StatusFormatter $statusFormatter;
	private string $photoDNAUrl;
	private ?string $httpProxy;
	private string $photoDNASubscriptionKey;

	/**
	 * @param ServiceOptions $options
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param StatsdDataFactoryInterface $perDbNameStatsdDataFactory
	 * @param MediaModerationImageContentsLookup $mediaModerationImageContentsLookup
	 * @param StatusFormatter $statusFormatter
	 */
	public function __construct(
		ServiceOptions $options,
		HttpRequestFactory $httpRequestFactory,
		StatsdDataFactoryInterface $perDbNameStatsdDataFactory,
		MediaModerationImageContentsLookup $mediaModerationImageContentsLookup,
		StatusFormatter $statusFormatter
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->httpRequestFactory = $httpRequestFactory;
		$this->photoDNAUrl = $options->get( 'MediaModerationPhotoDNAUrl' );
		$this->photoDNASubscriptionKey = $options->get( 'MediaModerationPhotoDNASubscriptionKey' );
		$this->httpProxy = $options->get( 'MediaModerationHttpProxy' );
		$this->perDbNameStatsdDataFactory = $perDbNameStatsdDataFactory;
		$this->mediaModerationImageContentsLookup = $mediaModerationImageContentsLookup;
		$this->statusFormatter = $statusFormatter;
	}

	/** @inheritDoc */
	public function check( $file ): StatusValue {
		$requestStatus = $this->getRequest( $file );
		if ( !$requestStatus->isGood() ) {
			return $requestStatus;
		}
		/** @var MWHttpRequest $request */
		$request = $requestStatus->getValue();
		$start = microtime( true );
		$status = $request->execute();
		$delay = microtime( true ) - $start;
		$this->perDbNameStatsdDataFactory->timing(
			'MediaModeration.PhotoDNAServiceProviderRequestTime',
			1000 * $delay
		);
		if ( $status->isOK() ) {
			$this->perDbNameStatsdDataFactory->increment( 'MediaModeration.PhotoDNAServiceProvider.Execute.OK' );
		} else {
			$this->perDbNameStatsdDataFactory->increment(
				'MediaModeration.PhotoDNAServiceProvider.Execute.Error.' . $request->getStatus()
			);
		}
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
		$jsonParseStatus = FormatJson::parse( $rawResponse, FormatJson::FORCE_ASSOC );
		$responseJson = $jsonParseStatus->getValue();
		if ( !$jsonParseStatus->isOK() || !is_array( $responseJson ) ) {
			$this->perDbNameStatsdDataFactory->increment(
				'MediaModeration.PhotoDNAServiceProvider.Execute.InvalidJsonResponse'
			);
			return StatusValue::newFatal( new RawMessage(
				'PhotoDNA returned an invalid JSON body for ' . $file->getName() . '. Parse error: ' .
				$this->statusFormatter->getWikiText( $jsonParseStatus )
			) );
		}
		$response = Response::newFromArray( $responseJson, $rawResponse );
		$this->perDbNameStatsdDataFactory->increment(
			'MediaModeration.PhotoDNAServiceProvider.Execute.StatusCode' . $response->getStatusCode()
		);
		return $this->createStatusFromResponse( $response );
	}

	/**
	 * @param File|ArchivedFile $file
	 * @return StatusValue
	 */
	private function getRequest( $file ): StatusValue {
		$imageContentsStatus = $this->mediaModerationImageContentsLookup->getImageContents( $file );
		if ( !$imageContentsStatus->isOK() ) {
			// Hide the thumbnail contents and mime type from the caller of ::getRequest by
			// creating a standard StatusValue and merging the ImageContentsStatus into it.
			// This is done as these values will be null if we have reached here.
			return StatusValue::newGood()->merge( $imageContentsStatus );
		}
		$options = [
			'method' => 'POST',
			'postData' => $imageContentsStatus->getImageContents()
		];
		if ( $this->httpProxy ) {
			$options['proxy'] = $this->httpProxy;
		}
		$request = $this->httpRequestFactory->create(
			$this->photoDNAUrl,
			$options,
			__METHOD__
		);
		$request->setHeader( 'Content-Type', $imageContentsStatus->getMimeType() );
		$request->setHeader( 'Ocp-Apim-Subscription-Key', $this->photoDNASubscriptionKey );
		return StatusValue::newGood( $request );
	}
}
