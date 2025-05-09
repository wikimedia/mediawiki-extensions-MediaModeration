<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Services;

use MediaTransformError;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\Exception\RuntimeException;
use MediaWiki\Extension\MediaModeration\Media\ThumborThumbnailImage;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationImageContentsLookup;
use MediaWiki\FileRepo\File\ArchivedFile;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\RawMessage;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use MWHttpRequest;
use PHPUnit\Framework\MockObject\MockObject;
use StatusValue;
use ThumbnailImage;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationImageContentsLookup
 * @group MediaModeration
 */
class MediaModerationImageContentsLookupTest extends MediaWikiIntegrationTestCase {
	use MockServiceDependenciesTrait;
	use MockHttpTrait;
	use MediaModerationStatsFactoryHelperTestTrait;

	private const CONSTRUCTOR_OPTIONS_DEFAULTS = [
		'MediaModerationThumbnailWidth' => 330,
		'MediaModerationThumborRequestTimeout' => 60,
		MainConfigNames::ThumbnailSteps => null,
		'MediaModerationThumbnailMinimumSize' => [ 'width' => 160, 'height' => 160 ],
	];

	public function testCheckOnThumbnailContentsInvalid() {
		// Define a mock File to return a mock ThumbnailImage
		$mockThumbnail = $this->createMock( ThumbnailImage::class );
		$mockThumbnail->method( 'getStoragePath' )
			->willReturn( 'test' );
		$mockFile = $this->createMock( File::class );
		$mockFile->method( 'transform' )
			->willReturn( $mockThumbnail );
		// Mock the FileBackend to always return false for the path 'test'
		$fileBackendMock = $this->createMock( FileBackend::class );
		$fileBackendMock->method( 'getFileContentsMulti' )
			->willReturn( [ 'test' => false ] );
		$mockFile->method( 'getRepo' )
			->willReturn( $this->createMock( LocalRepo::class ) );
		// Call the method under test
		/** @var MediaModerationImageContentsLookup $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationImageContentsLookup::class,
			[
				'options' => new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'fileBackend' => $fileBackendMock,
				'statsFactory' => $this->getServiceContainer()->getStatsFactory(),
			]
		);
		$checkStatus = $objectUnderTest->getImageContents( $mockFile );
		$this->assertStatusNotOK(
			$checkStatus,
			'::check should return a fatal status on a RuntimeException.'
		);
		$this->assertCounterIncremented( 'image_contents_lookup_failure_total' );
	}

	/** @dataProvider provideGetThumbnailForFileOnFailure */
	public function testGetThumbnailForFileOnFailure( $thumbnailOrThumbnailClassName ) {
		// If $thumbnail is false, then return false from ::transform.
		// Otherwise return a mock of that class.
		if ( is_string( $thumbnailOrThumbnailClassName ) ) {
			$thumbnail = $this->createMock( $thumbnailOrThumbnailClassName );
			if ( $thumbnailOrThumbnailClassName === MediaTransformError::class ) {
				// If the class name is MediaTransformError, then define
				// ::toText as it is called when getting the exception message.
				$thumbnail->method( 'toText' )
					->willReturn( 'test' );
			} elseif ( $thumbnailOrThumbnailClassName === ThumbnailImage::class ) {
				// If the class name is ThumbnailImage, then get hasFile
				// to return false to cause an exception.
				$thumbnail->method( 'hasFile' )
					->willReturn( false );
			}
		} else {
			$thumbnail = $thumbnailOrThumbnailClassName;
		}
		// Define a mock File class that returns a pre-defined ::getName
		$mockFile = $this->createMock( File::class );
		$mockFile->method( 'getName' )
			->willReturn( 'Test.png' );
		$mockFile->method( 'transform' )
			->willReturn( $thumbnail );
		$mockFile->method( 'getRepo' )
			->willReturn( $this->createMock( LocalRepo::class ) );
		$mockFile->expects( $this->once() )->method( 'transform' )
			->with( [ 'width' => 330 ] );
		// Call the method under test
		/** @var MediaModerationImageContentsLookup $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationImageContentsLookup::class,
			[
				'options' => new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'statsFactory' => $this->getServiceContainer()->getStatsFactory(),
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$actualStatus = $objectUnderTest->getThumbnailForFile( $mockFile );
		$this->assertStatusNotOK( $actualStatus );
		// Check that the warning was sent to Prometheus
		$this->assertCounterIncremented(
			'image_contents_lookup_error_total',
			[ 'image_type' => 'thumbnail', 'error_type' => 'transform', 'error' => 'failed' ]
		);
		$this->assertTimingObserved(
			'image_contents_lookup_thumbnail_transform_time',
			[ 'method' => 'php' ]
		);
	}

	public static function provideGetThumbnailForFileOnFailure() {
		return [
			'::transform returns false' => [ false ],
			'::transform returns MediaTransformError' => [ MediaTransformError::class ],
			'::transform returns an unexpected class' => [ RuntimeException::class ],
			'::transform returns ThumbnailImage with ::hasFile as false' => [ ThumbnailImage::class ],
		];
	}

	public function testGetThumbnailForFileWhenLocalCopyPathReturnsFalse() {
		$thumbnail = $this->createMock( ThumbnailImage::class );
		$thumbnail->method( 'hasFile' )
			->willReturn( true );
		$thumbnail->method( 'getLocalCopyPath' )
			->willReturn( false );
		$this->testGetThumbnailForFileOnFailure( $thumbnail );
	}

	/** @dataProvider provideGetThumbnailForFileForDifferentExpectedThumbnailWidths */
	public function testGetThumbnailForFileForDifferentExpectedThumbnailWidths(
		$fileWidth, $fileHeight, $expectedThumbnailWidth, $configOverrides = []
	) {
		// Mock that the attempt to get the thumbnail fails. We don't need to test beyond the point where
		// File::transform is called.
		$thumbnail = $this->createMock( ThumbnailImage::class );
		$thumbnail->method( 'hasFile' )
			->willReturn( false );

		// Define a mock File class that returns a pre-defined name, height, width. This also expects
		// that a call to ::transform occurs with the expected width.
		$mockFile = $this->createMock( File::class );
		$mockFile->method( 'getName' )
			->willReturn( 'Test.png' );
		$mockFile->method( 'getRepo' )
			->willReturn( $this->createMock( LocalRepo::class ) );
		$mockFile->method( 'getWidth' )
			->willReturn( $fileWidth );
		$mockFile->method( 'getHeight' )
			->willReturn( $fileHeight );
		$mockFile->expects( $this->once() )
			->method( 'transform' )
			->willReturnCallback( function ( $params ) use ( $expectedThumbnailWidth, $thumbnail ) {
				$this->assertSame( $expectedThumbnailWidth, $params['width'] );
				return $thumbnail;
			} );

		// Call the method under test
		/** @var MediaModerationImageContentsLookup $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationImageContentsLookup::class,
			[
				'options' => new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( array_merge( self::CONSTRUCTOR_OPTIONS_DEFAULTS, $configOverrides ) )
				),
				'statsFactory' => $this->getServiceContainer()->getStatsFactory(),
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$actualStatus = $objectUnderTest->getThumbnailForFile( $mockFile );

		// PHPUnit expectations will check that the call to ::transform was made with the expected width.
		// Just check that the return status is not okay, given that we mocked the thumbnail to be invalid.
		$this->assertStatusNotOK( $actualStatus );
	}

	public static function provideGetThumbnailForFileForDifferentExpectedThumbnailWidths() {
		return [
			'File width and height are undefined' => [ 0, 0, 330 ],
			'File width is undefined' => [ 0, 123, 330 ],
			'File height is undefined' => [ 123, 0, 330 ],
			'Default width meets requirements' => [
				340, 340, 160, [ 'MediaModerationThumbnailWidth' => 160 ],
			],
			'Thumbnail step meets requirements' => [
				340, 300, 190,
				[
					'MediaModerationThumbnailWidth' => 160,
					MainConfigNames::ThumbnailSteps => [ 30, 200, 170, 190 ],
				],
			],
			'Cannot use pre-defined widths when width and height equal' => [
				123, 123, 160, [ MainConfigNames::ThumbnailSteps => [ 1 ], 'MediaModerationThumbnailWidth' => 1 ],
			],
			'Cannot use pre-defined widths when file wider than tall' => [
				502, 454, 177, [ MainConfigNames::ThumbnailSteps => [ 1 ], 'MediaModerationThumbnailWidth' => 1 ],
			],
			'Cannot use pre-defined widths when file taller than wide' => [
				400, 584, 160, [ MainConfigNames::ThumbnailSteps => [ 1 ], 'MediaModerationThumbnailWidth' => 1 ],
			],
			'Cannot use pre-defined widths when file wider than tall, and smaller than allowed thumbnail' => [
				30, 18, 267, [ MainConfigNames::ThumbnailSteps => [ 1 ], 'MediaModerationThumbnailWidth' => 1 ],
			],
			'Cannot use pre-defined widths when file taller than wide, and smaller than allowed thumbnail' => [
				18, 30, 160, [ MainConfigNames::ThumbnailSteps => [ 1 ], 'MediaModerationThumbnailWidth' => 1 ],
			],
		];
	}

	public function testGetThumbnailForFileWithThumborRequest() {
		// Define a mock File class that returns a pre-defined ::getName
		$mockFile = $this->createMock( File::class );
		$mockFile->method( 'getName' )
			->willReturn( 'Test.png' );
		$mockFile->method( 'getThumbUrl' )
			->willReturn( 'http://thumb' );
		$mockFile->expects( $this->never() )->method( 'transform' );
		$mockLocalRepo = $this->createMock( LocalRepo::class );
		$mockLocalRepo->method( 'getThumbProxyUrl' )->willReturn( 'http://foo' );
		$mockLocalRepo->method( 'getThumbProxySecret' )->willReturn( 'secret' );
		$mockFile->method( 'getRepo' )->willReturn( $mockLocalRepo );

		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->method( 'execute' )->willReturn(
			StatusValue::newGood()
		);
		$imageContents = file_get_contents( __DIR__ . '/../../fixtures/489px-Lagoon_Nebula.jpg' );
		$mockRequest->method( 'getContent' )->willReturn( $imageContents );
		$mockRequest->method( 'getResponseHeader' )->with( 'content-type' )->willReturn( 'image/jpeg' );
		$mockRequest->expects( $this->once() )->method( 'execute' );
		$mockRequest->expects( $this->once() )->method( 'setHeader' );
		$mockRequest->method( 'getStatus' )->willReturn( 200 );
		$this->installMockHttp( $mockRequest );

		// Call the method under test
		/** @var MediaModerationImageContentsLookup $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationImageContentsLookup::class,
			[
				'options' => new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'httpRequestFactory' => $this->getServiceContainer()->getHttpRequestFactory(),
				'statsFactory' => $this->getServiceContainer()->getStatsFactory(),
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$actualStatus = $objectUnderTest->getThumbnailForFile( $mockFile );
		$this->assertStatusOK( $actualStatus );
		/** @var ThumborThumbnailImage $thumbnail */
		$thumbnail = $actualStatus->getValue();
		$this->assertEquals( 480, $thumbnail->getHeight() );
		$this->assertEquals( 489, $thumbnail->getWidth() );
		$this->assertEquals( $imageContents, $thumbnail->getContent() );

		$this->assertCounterNotIncremented( 'image_contents_lookup_error_total' );
		$this->assertCounterIncremented(
			'image_contents_lookup_thumbor_request_total',
			[ 'status_code' => 200 ]
		);
		$this->assertTimingObserved(
			'image_contents_lookup_thumbnail_transform_time',
			[ 'method' => 'thumbor' ]
		);
	}

	public function testGetThumbnailForFileWithThumborRequestThatFails() {
		// Define a mock ThumbnailImage that says it has a valid thumbnail file
		$thumbnail = $this->createMock( ThumbnailImage::class );
		$thumbnail->method( 'hasFile' )
			->willReturn( true );
		$thumbnail->method( 'getLocalCopyPath' )
			->willReturn( 'abc' );
		// Define a mock File class that returns a pre-defined ::getName
		$mockFile = $this->createMock( File::class );
		$mockFile->method( 'getName' )
			->willReturn( 'Test.png' );
		$mockFile->method( 'getThumbUrl' )
			->willReturn( 'http://thumb' );
		$mockFile->expects( $this->once() )->method( 'transform' )
			->with( [ 'width' => 330 ] )
			->willReturn( $thumbnail );

		// Mock that Thumbor can be used, so that it is tried first.
		$mockLocalRepo = $this->createMock( LocalRepo::class );
		$mockLocalRepo->method( 'getThumbProxyUrl' )->willReturn( 'http://foo' );
		$mockLocalRepo->method( 'getThumbProxySecret' )->willReturn( 'secret' );
		$mockFile->method( 'getRepo' )->willReturn( $mockLocalRepo );

		// Expect a request to Thumbor, but pretend that the request fails.
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->method( 'execute' )->willReturn(
			StatusValue::newFatal( 'http-request-error' )
		);
		$mockRequest->expects( $this->never() )->method( 'getContent' );
		$mockRequest->method( 'getResponseHeader' )->with( 'content-type' )->willReturn( 'image/jpeg' );
		$mockRequest->expects( $this->once() )->method( 'execute' );
		$mockRequest->expects( $this->once() )->method( 'setHeader' );
		$mockRequest->method( 'getStatus' )->willReturn( 500 );
		$this->installMockHttp( $mockRequest );

		// Call the method under test
		/** @var MediaModerationImageContentsLookup $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationImageContentsLookup::class,
			[
				'options' => new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'httpRequestFactory' => $this->getServiceContainer()->getHttpRequestFactory(),
				'statsFactory' => $this->getServiceContainer()->getStatsFactory(),
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$actualStatus = $objectUnderTest->getThumbnailForFile( $mockFile );
		$this->assertStatusOK( $actualStatus );
		/** @var ThumborThumbnailImage $thumbnail */
		$actualThumbnail = $actualStatus->getValue();
		$this->assertSame( $thumbnail, $actualThumbnail );

		$this->assertCounterIncremented(
			'image_contents_lookup_error_total',
			[ 'image_type' => 'thumbnail', 'error_type' => 'thumbor_transform', 'error' => 'failed' ]
		);
		$this->assertCounterIncremented(
			'image_contents_lookup_thumbor_request_total',
			[ 'status_code' => 500 ]
		);
		$this->assertTimingObserved(
			'image_contents_lookup_thumbnail_transform_time',
			[ 'method' => 'php' ]
		);
	}

	/** @dataProvider provideGetThumbnailMimeType */
	public function testGetThumbnailMimeType(
		$fromExtensionResult, $guessFromContentsResult, $expectedReturnValue, $expectedPrometheusErrorLabel
	) {
		// Create a mock ThumbnailImage that has a mocked file extension and path
		$mockThumbnailImage = $this->createMock( ThumbnailImage::class );
		$mockThumbnailImage->method( 'getExtension' )
			->willReturn( 'mock-extension' );
		$mockThumbnailImage->method( 'getLocalCopyPath' )
			->willReturn( 'mock-path' );
		$mockThumbnailImage->method( 'getFile' )
			->willReturn( $this->createMock( File::class ) );
		// Create a mock MimeAnalyzer that has the ::getMimeTypeFromExtensionOrNull and ::guessMimeType methods
		// mocked to return the values in $fromExtensionResult and $guessFromContentsResult respectively.
		$mockMimeAnalyzer = $this->createMock( MimeAnalyzer::class );
		$mockMimeAnalyzer->method( 'getMimeTypeFromExtensionOrNull' )
			->with( 'mock-extension' )
			->willReturn( $fromExtensionResult );
		$mockMimeAnalyzer->method( 'guessMimeType' )
			->with( 'mock-path' )
			->willReturn( $guessFromContentsResult );

		// Get the object under test
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationImageContentsLookup::class,
			[
				'options' => new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'mimeAnalyzer' => $mockMimeAnalyzer,
				'statsFactory' => $this->getServiceContainer()->getStatsFactory(),
			]
		);
		// Call the method under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$actualStatus = $objectUnderTest->getThumbnailMimeType( $mockThumbnailImage );

		if ( $expectedReturnValue === null ) {
			$this->assertStatusNotOK( $actualStatus );
			$this->assertCounterIncremented(
				'image_contents_lookup_error_total',
				[ 'image_type' => 'thumbnail', 'error_type' => 'mime', 'error' => $expectedPrometheusErrorLabel ]
			);
		} else {
			$this->assertStatusGood( $actualStatus );
			$this->assertCounterNotIncremented( 'image_contents_lookup_error_total' );
		}
		$this->assertSame(
			$expectedReturnValue,
			$actualStatus->getValue(),
			'Return value of ::getThumbnailMimeType was not as expected.'
		);
	}

	public static function provideGetThumbnailMimeType() {
		return [
			'Thumbnail type is got from thumbnail extension' => [ 'image/jpeg', '', 'image/jpeg', null ],
			'Thumbnail type is got from guessing using the file contents' => [ null, 'image/png', 'image/png', null ],
			'No mime type from either methods' => [ null, '', null, 'lookup_failed' ],
			'Unsupported mime type from extension method' => [ 'image/svg', '', null, 'unsupported' ],
			'Unsupported mime type from guess method' => [ null, 'image/svg', null, 'unsupported' ],
		];
	}

	/** @dataProvider provideGetThumbnailContents */
	public function testGetThumbnailContents(
		$mockThumbnailHeight, $mockThumbnailWidth, $mockStoragePathValue, $mockFileContentsValue, $expectedReturnValue,
		$expectedPrometheusErrorLabel
	) {
		// Create a mock ThumbnailImage that is mocked to return a given height, width, and storage
		// path value.
		$mockThumbnailImage = $this->createMock( ThumbnailImage::class );
		$mockThumbnailImage->method( 'getStoragePath' )
			->willReturn( $mockStoragePathValue );
		$mockThumbnailImage->method( 'getFile' )
			->willReturn( $this->createMock( File::class ) );
		$mockThumbnailImage->method( 'getWidth' )
			->willReturn( $mockThumbnailWidth );
		$mockThumbnailImage->method( 'getHeight' )
			->willReturn( $mockThumbnailHeight );
		// Create a mock FileBackend that returns the value of $mockFileContentsValue for ::getFileContents
		// if $mockStoragePathValue is truthy. Otherwise expect that ::getFileContents is never called.
		$mockFileBackend = $this->createMock( FileBackend::class );
		if ( !$mockStoragePathValue ) {
			// ::getFileContents is final, so have to mock ::getFileContentsMulti instead (which works fine).
			$mockFileBackend->expects( $this->never() )
				->method( 'getFileContentsMulti' );
		} else {
			// ::getFileContents is final, so have to mock ::getFileContentsMulti instead (which works fine).
			$mockFileBackend->expects( $this->once() )
				->method( 'getFileContentsMulti' )
				->willReturn( [ $mockStoragePathValue => $mockFileContentsValue ] );
		}

		// Get the object under test with the FileBackend as $mockFileBackend.
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationImageContentsLookup::class,
			[
				'options' => new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'fileBackend' => $mockFileBackend,
				'statsFactory' => $this->getServiceContainer()->getStatsFactory(),
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		// Call the method under test and expect that the return value is $expectedReturnValue
		$actualStatus = $objectUnderTest->getThumbnailContents( $mockThumbnailImage );

		if ( $expectedReturnValue === null ) {
			$this->assertStatusNotOK( $actualStatus );
			$this->assertCounterIncremented(
				'image_contents_lookup_error_total',
				[ 'image_type' => 'thumbnail', 'error_type' => 'contents', 'error' => $expectedPrometheusErrorLabel ]
			);
		} else {
			$this->assertStatusGood( $actualStatus );
			$this->assertCounterNotIncremented( 'image_contents_lookup_error_total' );
		}
		$this->assertSame(
			$expectedReturnValue,
			$actualStatus->getValue(),
			'Return value of ::getThumbnailContents was not as expected.'
		);
	}

	public static function provideGetThumbnailContents() {
		return [
			'Valid storage path and file contents' => [
				// The mock height of the thumbnail as returned by ThumbnailImage::getHeight
				200,
				// The mock width of the thumbnail as returned by ThumbnailImage::getWidth
				200,
				// The storage path for the thumbnail. Specify false to indicate that no attempt
				// should be made to access the contents.
				'test/test.png',
				// The mock thumbnail contents as returned by FileBackend::getFileContents
				'abcdef1234',
				// The expected return value of the method under test
				'abcdef1234',
				// If the return value is null, then what is the value for the error label on the Prometheus event
				null,
			],
			'Valid storage path, but invalid thumbnail contents' => [
				200, 200, 'test/test.png', false, null, 'lookup_failed',
			],
			'Thumbnail contents are too large' => [
				200, 200, 'test/test.png', str_repeat( '1', 4000001 ), null, 'too_large',
			],
			'Invalid storage path' => [ 200, 200, false, false, null, 'lookup_failed' ],
			'Thumbnail is not tall enough' => [ 100, 200, false, false, null, 'too_small' ],
			'Thumbnail is not wide enough' => [ 200, 100, false, false, null, 'too_small' ],
		];
	}

	/** @dataProvider provideGetFileContents */
	public function testGetFileContents(
		$mockFileSize, $mockFileHeight, $mockFileWidth, $mockStoragePathValue, $expectedReturnValue,
		$expectedPrometheusErrorLabel
	) {
		// Create a mock File that returns the values set for the test.
		$mockFile = $this->createMock( File::class );
		$mockFile->method( 'getPath' )
			->willReturn( $mockStoragePathValue );
		$mockFile->method( 'getSize' )
			->willReturn( $mockFileSize );
		$mockFile->method( 'getHeight' )
			->willReturn( $mockFileHeight );
		$mockFile->method( 'getWidth' )
			->willReturn( $mockFileWidth );
		// Create a mock FileBackend that returns the value of $mockFileContentsValue for ::getFileContents
		// if $mockStoragePathValue is truthy. Otherwise expect that ::getFileContents is never called.
		$mockFileBackend = $this->createMock( FileBackend::class );
		if ( !$mockStoragePathValue ) {
			// ::getFileContents is final, so have to mock ::getFileContentsMulti instead (which works fine).
			$mockFileBackend->expects( $this->never() )
				->method( 'getFileContentsMulti' );
		} else {
			// ::getFileContents is final, so have to mock ::getFileContentsMulti instead (which works fine).
			$mockFileBackend->expects( $this->once() )
				->method( 'getFileContentsMulti' )
				->willReturn( [ $mockStoragePathValue => $expectedReturnValue ?? false ] );
		}

		// Get the object under test with the FileBackend as $mockFileBackend.
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationImageContentsLookup::class,
			[
				'options' => new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'fileBackend' => $mockFileBackend,
				'statsFactory' => $this->getServiceContainer()->getStatsFactory(),
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		// Call the method under test and expect that the return value is $expectedReturnValue
		$actualStatus = $objectUnderTest->getFileContents( $mockFile );

		if ( $expectedReturnValue === null ) {
			$this->assertStatusNotOK( $actualStatus );
			$this->assertCounterIncremented(
				'image_contents_lookup_error_total',
				[ 'image_type' => 'source_file', 'error_type' => 'contents', 'error' => $expectedPrometheusErrorLabel ]
			);
		} else {
			$this->assertStatusGood( $actualStatus );
			$this->assertCounterNotIncremented( 'image_contents_lookup_error_total' );
		}
		$this->assertSame(
			$expectedReturnValue,
			$actualStatus->getValue(),
			'Return value of ::getFileContents was not as expected.'
		);
	}

	public static function provideGetFileContents() {
		return [
			'Valid storage path and file contents' => [
				// Value returned by File::getSize or ArchivedFile::getSize. Indicates size of image in bytes.
				150,
				// Height of the image as returned by File/ArchivedFile ::getHeight.
				300,
				// Width of the image as returned by File/ArchivedFile ::getWidth.
				300,
				// Mock storage path for the file. Define this as false to indicate that
				// the code should never try to access a file with this path.
				'test/test.png',
				// The mock file contents which should be returned by the method.
				// Null indicates that the file contents should not be got because of a failure.
				'abcdef1234',
				// If the return value is null, then what is the value for the error label on the Prometheus event
				null,
			],
			'Valid storage path, but invalid thumbnail contents' => [
				150, 300, 300, 'test/test.png', null, 'lookup_failed',
			],
			'Invalid storage path' => [ 150, 300, 300, false, null, 'lookup_failed' ],
			'File size is too large' => [ 4000001, 300, 300, false, null, 'too_large' ],
			'File size is false but height is too small' => [ false, 150, 300, false, null, 'too_small' ],
			'File size width is too small' => [ 300, 300, 150, false, null, 'too_small' ],
			'File size height is too small' => [ 300, 150, 300, false, null, 'too_small' ],
		];
	}

	/** @dataProvider provideGetImageContents */
	public function testGetImageContents(
		$fileContentsStatusGood, $thumbnailStatusGood, $thumbnailContentsStatusGood, $thumbnailMimeTypeStatusGood,
		$fileObjectClassName, $fileMimeType, $expectSourceFileUsedMetricToBeIncremented, $expectStatusIsGood,
		$expectStatusIsOkay
	) {
		// Get the object under test, with several protected methods mocked.
		$objectUnderTest = $this->getMockBuilder( MediaModerationImageContentsLookup::class )
			->onlyMethods( [
				'getFileContents',
				'getThumbnailForFile',
				'getThumbnailContents',
				'getThumbnailMimeType',
			] )
			->setConstructorArgs( [
				new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				$this->createMock( FileBackend::class ),
				$this->getServiceContainer()->getStatsFactory(),
				$this->createMock( MimeAnalyzer::class ),
				$this->createMock( LocalRepo::class ),
				$this->createMock( HttpRequestFactory::class )
			] )
			->getMock();
		// Mock the StatusValue objects returned by the methods defined to be mocked
		$this->mockStatusBasedOnBoolean(
			$objectUnderTest, 'getFileContents', $fileContentsStatusGood, 'mock-file-contents'
		);
		$this->mockStatusBasedOnBoolean(
			$objectUnderTest, 'getThumbnailForFile', $thumbnailStatusGood,
			$this->createMock( ThumbnailImage::class )
		);
		$this->mockStatusBasedOnBoolean(
			$objectUnderTest, 'getThumbnailContents', $thumbnailContentsStatusGood, 'mock-file-contents'
		);
		$this->mockStatusBasedOnBoolean(
			$objectUnderTest, 'getThumbnailMimeType', $thumbnailMimeTypeStatusGood, 'image/jpeg'
		);
		// Create a mock object with class name $fileObjectClassName (should extend or be File or ArchivedFile)
		/** @var File|ArchivedFile|MockObject $mockFile */
		$mockFile = $this->createMock( $fileObjectClassName );
		$mockFile->method( 'getMimeType' )
			->willReturn( $fileMimeType );
		$actualStatus = $objectUnderTest->getImageContents( $mockFile );
		// Assert that the returned status is the expected of good, okay or not okay.
		if ( $expectStatusIsGood ) {
			$this->assertStatusGood( $actualStatus );
			$this->assertSame(
				$fileMimeType,
				$actualStatus->getMimeType(),
				'The status is good, but no mime type is specified for the image'
			);
			$this->assertCounterNotIncremented( 'image_contents_lookup_failure_total' );
		} elseif ( $expectStatusIsOkay ) {
			$this->assertStatusOK( $actualStatus );
			$this->assertSame(
				$fileMimeType,
				$actualStatus->getMimeType(),
				'The status is okay, but no mime type is specified for the image'
			);
			$this->assertCounterNotIncremented( 'image_contents_lookup_failure_total' );
		} else {
			$this->assertStatusNotOK( $actualStatus );
			$this->assertCounterIncremented( 'image_contents_lookup_failure_total' );
		}
		// Check if the source file used metric was incremented, and compare this to whether this was expected.
		if ( $expectSourceFileUsedMetricToBeIncremented ) {
			$this->assertCounterIncremented( 'image_contents_lookup_used_source_file_total' );
		} else {
			$this->assertCounterNotIncremented( 'image_contents_lookup_used_source_file_total' );
		}
	}

	public static function provideGetImageContents() {
		return [
			'ArchivedFile that has the source file contents' => [
				// Whether ::getFileContents returns a good status (true), a not good status (false),
				// or expects to never be called (null)
				true,
				// Same as first but for ::getThumbnailForFile
				null,
				// Same as first but for ::getThumbnailContents
				null,
				// Same as first but for ::getThumbnailMimeType
				null,
				// The name of the class used as the $file argument
				ArchivedFile::class,
				// The mime type for the source file
				'image/jpeg',
				// Should the image_contents_lookup_used_source_file_total counter metric be incremented?
				false,
				// Should the status returned by ::getImageContents be good (::isGood returns true)?
				true,
				// Should the status returned by ::getImageContents be okay (::isOK returns true)?
				false,
			],
			'ArchivedFile where source file contents collection failed' => [
				false, null, null, null, ArchivedFile::class, 'image/jpeg', false, false, false,
			],
			'ArchivedFile where source file is not supported' => [
				false, null, null, null, ArchivedFile::class, 'image/svg', false, false, false,
			],
			'File where no ThumbnailImage was generated but has file contents' => [
				true, false, null, null, File::class, 'image/jpeg', true, false, true,
			],
			'File where no ThumbnailImage was generated and no file contents' => [
				false, false, null, null, File::class, 'image/jpeg', false, false, false,
			],
			'File where thumbnail contents failed but has file contents' => [
				true, true, false, false, File::class, 'image/jpeg', true, false, true,
			],
			'File where thumbnail contents succeeds' => [
				null, true, true, true, File::class, 'image/jpeg', false, true, true,
			],
		];
	}

	private function mockStatusBasedOnBoolean(
		MockObject $mockObject, string $methodName, ?bool $shouldStatusBeGood, $valueForGoodStatus
	) {
		if ( $shouldStatusBeGood === null ) {
			$mockObject->expects( $this->never() )
				->method( $methodName );
		} elseif ( $shouldStatusBeGood ) {
			$mockObject->method( $methodName )
				->willReturn( StatusValue::newGood( $valueForGoodStatus ) );
		} else {
			$mockObject->method( $methodName )
				->willReturn( StatusValue::newFatal( new RawMessage( 'test' ) ) );
		}
	}
}
