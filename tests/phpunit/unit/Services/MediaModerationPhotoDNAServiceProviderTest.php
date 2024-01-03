<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Services;

use File;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationImageContentsLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationPhotoDNAServiceProvider;
use MediaWiki\Extension\MediaModeration\Status\ImageContentsLookupStatus;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\RawMessage;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use MWHttpRequest;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationPhotoDNAServiceProvider
 * @group MediaModeration
 */
class MediaModerationPhotoDNAServiceProviderTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	private const CONSTRUCTOR_OPTIONS_DEFAULTS = [
		'MediaModerationPhotoDNAUrl' => '',
		'MediaModerationPhotoDNASubscriptionKey' => '',
		'MediaModerationHttpProxy' => '',
	];

	public function testCheckOnFailedImageContentsLookup() {
		$mockFile = $this->createMock( File::class );
		// Create a mock MediaModerationImageContentsLookup service that always returns a status with a fatal error.
		$mockMediaModerationImageContentsLookup = $this->createMock( MediaModerationImageContentsLookup::class );
		$mockMediaModerationImageContentsLookup->method( 'getImageContents' )
			->with( $mockFile )
			->willReturn( ImageContentsLookupStatus::newFatal( new RawMessage( 'test' ) ) );
		// Call the method under test
		/** @var MediaModerationPhotoDNAServiceProvider $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationPhotoDNAServiceProvider::class,
			[
				'options' => new ServiceOptions(
					MediaModerationPhotoDNAServiceProvider::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'mediaModerationImageContentsLookup' => $mockMediaModerationImageContentsLookup
			]
		);
		$checkStatus = $objectUnderTest->check( $mockFile );
		$this->assertStatusNotOK(
			$checkStatus,
			'::check should return a fatal status if MediaModerationImageContentsLookup:: ' .
			'getImageContents returns a fatal status.'
		);
		$this->assertNotInstanceOf(
			ImageContentsLookupStatus::class,
			$checkStatus,
			'::check should return a StatusValue and not a ImageContentsLookupStatus, as the caller ' .
			'does not need access to the extra methods added in ImageContentsLookupStatus.'
		);
	}

	public function testGetRequestWithHttpProxy() {
		$mockFile = $this->createMock( File::class );
		// Create a mock MediaModerationImageContentsLookup service that always returns a good status.
		$mockStatus = ImageContentsLookupStatus::newGood()
			->setImageContents( 'test' )
			->setMimeType( 'image/jpeg' );
		$mockMediaModerationImageContentsLookup = $this->createMock( MediaModerationImageContentsLookup::class );
		$mockMediaModerationImageContentsLookup->method( 'getImageContents' )
			->with( $mockFile )
			->willReturn( $mockStatus );
		// Create a mock MWHttpRequest to be returned by the mock HttpRequestFactory::create method
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->expects( $this->exactly( 2 ) )
			->method( 'setHeader' )
			->willReturnMap( [
				[ 'Content-Type', $mockStatus->getMimeType(), null ],
				[ 'Ocp-Apim-Subscription-Key', 'photo-dna-key-test', null ],
			] );
		// Create a mock HttpRequestFactory that will expect that the expected options and URL are passed
		$mockHttpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$mockHttpRequestFactory->method( 'create' )
			->with(
				'photo-dna-url-test',
				[
					'method' => 'POST',
					'postData' => $mockStatus->getImageContents(),
					'proxy' => 'photo-dna-proxy-test',
				]
			)
			->willReturn( $mockRequest );
		// Call the method under test
		/** @var MediaModerationPhotoDNAServiceProvider $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationPhotoDNAServiceProvider::class,
			[
				'options' => new ServiceOptions(
					MediaModerationPhotoDNAServiceProvider::CONSTRUCTOR_OPTIONS,
					new HashConfig( [
						'MediaModerationPhotoDNAUrl' => 'photo-dna-url-test',
						'MediaModerationPhotoDNASubscriptionKey' => 'photo-dna-key-test',
						'MediaModerationHttpProxy' => 'photo-dna-proxy-test',
					] )
				),
				'mediaModerationImageContentsLookup' => $mockMediaModerationImageContentsLookup,
				'httpRequestFactory' => $mockHttpRequestFactory,
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			$mockRequest,
			$objectUnderTest->getRequest( $mockFile )->getValue(),
			'::getRequest returned a MWHttpRequest object different to the expected object.'
		);
	}
}
