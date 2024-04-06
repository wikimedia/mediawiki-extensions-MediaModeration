<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Services;

use ArchivedFile;
use File;
use FileBackend;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use LocalRepo;
use MediaTransformError;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\Exception\RuntimeException;
use MediaWiki\Extension\MediaModeration\Media\ThumborThumbnailImage;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationImageContentsLookup;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\RawMessage;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use MimeAnalyzer;
use MWHttpRequest;
use PHPUnit\Framework\MockObject\MockObject;
use StatusValue;
use ThumbnailImage;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationImageContentsLookup
 * @group MediaModeration
 */
class MediaModerationImageContentsLookupTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	private const CONSTRUCTOR_OPTIONS_DEFAULTS = [
		'MediaModerationThumbnailWidth' => 320,
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
				'fileBackend' => $fileBackendMock
			]
		);
		$checkStatus = $objectUnderTest->getImageContents( $mockFile );
		$this->assertStatusNotOK(
			$checkStatus,
			'::check should return a fatal status on a thrown RuntimeException.'
		);
	}

	/** @dataProvider provideGetThumbnailForFile */
	public function testGetThumbnailForFile( $thumbnailClassName ) {
		// If $thumbnail is false, then return false from ::transform.
		// Otherwise return a mock of that class.
		if ( $thumbnailClassName ) {
			$thumbnail = $this->createMock( $thumbnailClassName );
			if ( $thumbnailClassName === MediaTransformError::class ) {
				// If the class name is MediaTransformError, then define
				// ::toText as it is called when getting the exception message.
				$thumbnail->method( 'toText' )
					->willReturn( 'test' );
			} elseif ( $thumbnailClassName === ThumbnailImage::class ) {
				// If the class name is ThumbnailImage, then get hasFile
				// to return false to cause an exception.
				$thumbnail->method( 'hasFile' )
					->willReturn( false );
			}
		} else {
			$thumbnail = $thumbnailClassName;
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
			->with( [ 'width' => 320 ] );
		// Define a mock StatsdDataFactoryInterface that expects a call to ::increment.
		$mockPerDbNameStatsdDataFactory = $this->createMock( StatsdDataFactoryInterface::class );
		$mockPerDbNameStatsdDataFactory->expects( $this->once() )
			->method( 'increment' );
		// Call the method under test
		/** @var MediaModerationImageContentsLookup $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationImageContentsLookup::class,
			[
				'options' => new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'perDbNameStatsdDataFactory' => $mockPerDbNameStatsdDataFactory,
				'httpRequestFactory' => $this->createMock( HttpRequestFactory::class )
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$actualStatus = $objectUnderTest->getThumbnailForFile( $mockFile );
		$this->assertStatusNotOK( $actualStatus );
	}

	public static function provideGetThumbnailForFile() {
		return [
			'::transform returns false' => [ false ],
			'::transform returns MediaTransformError' => [ MediaTransformError::class ],
			'::transform returns an unexpected class' => [ RuntimeException::class ],
			'::transform returns ThumbnailImage with ::hasFile as false' => [ ThumbnailImage::class ],
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
		// Define a mock StatsdDataFactoryInterface that expects a call to ::increment.
		$mockPerDbNameStatsdDataFactory = $this->createMock( StatsdDataFactoryInterface::class );
		$mockPerDbNameStatsdDataFactory->expects( $this->never() )
			->method( 'increment' );
		$mockPerDbNameStatsdDataFactory->expects( $this->once() )
			->method( 'timing' )
			->with( 'MediaModeration.PhotoDNAServiceProviderThumbnailTransformThumborRequest' );
		$mockHttpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->method( 'execute' )->willReturn(
			StatusValue::newGood()
		);
		$imageContents = file_get_contents( __DIR__ . '/../../fixtures/489px-Lagoon_Nebula.jpg' );
		$mockRequest->method( 'getContent' )->willReturn( $imageContents );
		$mockRequest->method( 'getResponseHeader' )->with( 'content-type' )->willReturn( 'image/jpeg' );
		$mockRequest->expects( $this->once() )->method( 'execute' );
		$mockRequest->expects( $this->once() )->method( 'setHeader' );

		$mockHttpRequestFactory->method( 'create' )->willReturn( $mockRequest );
		$mockHttpRequestFactory->expects( $this->once() )->method( 'create' );
		// Call the method under test
		/** @var MediaModerationImageContentsLookup $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationImageContentsLookup::class,
			[
				'options' => new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'perDbNameStatsdDataFactory' => $mockPerDbNameStatsdDataFactory,
				'httpRequestFactory' => $mockHttpRequestFactory
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
	}

	/** @dataProvider provideGetThumbnailMimeType */
	public function testGetThumbnailMimeType( $fromExtensionResult, $guessFromContentsResult, $expectedReturnValue ) {
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
		$mockMimeAnalyzer = $this->createMock( \MimeAnalyzer::class );
		$mockMimeAnalyzer->method( 'getMimeTypeFromExtensionOrNull' )
			->with( 'mock-extension' )
			->willReturn( $fromExtensionResult );
		$mockMimeAnalyzer->method( 'guessMimeType' )
			->with( 'mock-path' )
			->willReturn( $guessFromContentsResult );
		// Define a mock StatsdDataFactoryInterface that expects a call to ::increment if $expectedReturnValue is null.
		$mockPerDbNameStatsdDataFactory = $this->createMock( StatsdDataFactoryInterface::class );
		if ( $expectedReturnValue === null ) {
			$mockPerDbNameStatsdDataFactory->expects( $this->once() )
				->method( 'increment' );
		} else {
			$mockPerDbNameStatsdDataFactory->expects( $this->never() )
				->method( 'increment' );
		}
		// Get the object under test
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationImageContentsLookup::class,
			[
				'options' => new ServiceOptions(
					MediaModerationImageContentsLookup::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'mimeAnalyzer' => $mockMimeAnalyzer,
				'perDbNameStatsdDataFactory' => $mockPerDbNameStatsdDataFactory,
			]
		);
		// Call the method under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$actualStatus = $objectUnderTest->getThumbnailMimeType( $mockThumbnailImage );
		if ( $expectedReturnValue === null ) {
			$this->assertStatusNotOK( $actualStatus );
		} else {
			$this->assertStatusGood( $actualStatus );
		}
		$this->assertSame(
			$expectedReturnValue,
			$actualStatus->getValue(),
			'Return value of ::getThumbnailMimeType was not as expected.'
		);
	}

	public static function provideGetThumbnailMimeType() {
		return [
			'Thumbnail type is got from thumbnail extension' => [ 'image/jpeg', '', 'image/jpeg' ],
			'Thumbnail type is got from guessing using the file contents' => [ null, 'image/png', 'image/png' ],
			'No mime type from either methods' => [ null, '', null ],
			'Unsupported mime type from extension method' => [ 'image/svg', '', null ],
			'Unsupported mime type from guess method' => [ null, 'image/svg', null ],
		];
	}

	/** @dataProvider provideGetThumbnailContents */
	public function testGetThumbnailContents(
		$mockThumbnailHeight, $mockThumbnailWidth, $mockStoragePathValue, $mockFileContentsValue, $expectedReturnValue
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
		// Define a mock StatsdDataFactoryInterface that expects a call to ::increment if $expectedReturnValue is null.
		$mockPerDbNameStatsdDataFactory = $this->createMock( StatsdDataFactoryInterface::class );
		if ( $expectedReturnValue === null ) {
			$mockPerDbNameStatsdDataFactory->expects( $this->once() )
				->method( 'increment' );
		} else {
			$mockPerDbNameStatsdDataFactory->expects( $this->never() )
				->method( 'increment' );
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
				'perDbNameStatsdDataFactory' => $mockPerDbNameStatsdDataFactory,
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		// Call the method under test and expect that the return value is $expectedReturnValue
		$actualStatus = $objectUnderTest->getThumbnailContents( $mockThumbnailImage );
		if ( $expectedReturnValue === null ) {
			$this->assertStatusNotOK( $actualStatus );
		} else {
			$this->assertStatusGood( $actualStatus );
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
				'abcdef1234'
			],
			'Valid storage path, but invalid thumbnail contents' => [ 200, 200, 'test/test.png', false, null ],
			'Thumbnail contents are too large' => [
				200, 200, 'test/test.png', str_repeat( '1', 4000001 ), null
			],
			'Invalid storage path' => [ 200, 200, false, false, null ],
			'Thumbnail is not tall enough' => [ 100, 200, false, false, null ],
			'Thumbnail is not wide enough' => [ 200, 100, false, false, null ],
		];
	}

	/** @dataProvider provideGetFileContents */
	public function testGetFileContents(
		$mockFileSize, $mockFileHeight, $mockFileWidth, $mockStoragePathValue, $expectedReturnValue
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
		// Define a mock StatsdDataFactoryInterface that expects a call to ::increment if $expectedReturnValue is null.
		$mockPerDbNameStatsdDataFactory = $this->createMock( StatsdDataFactoryInterface::class );
		if ( $expectedReturnValue === null ) {
			$mockPerDbNameStatsdDataFactory->expects( $this->once() )
				->method( 'increment' );
		} else {
			$mockPerDbNameStatsdDataFactory->expects( $this->never() )
				->method( 'increment' );
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
				'perDbNameStatsdDataFactory' => $mockPerDbNameStatsdDataFactory,
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		// Call the method under test and expect that the return value is $expectedReturnValue
		$actualStatus = $objectUnderTest->getFileContents( $mockFile );
		if ( $expectedReturnValue === null ) {
			$this->assertStatusNotOK( $actualStatus );
		} else {
			$this->assertStatusGood( $actualStatus );
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
				'abcdef1234'
			],
			'Valid storage path, but invalid thumbnail contents' => [ 150, 300, 300, 'test/test.png', null ],
			'Invalid storage path' => [ 150, 300, 300, false, null ],
			'File size is too large' => [ 4000001, 300, 300, false, null ],
			'File size is false but height is too small' => [ false, 150, 300, false, null ],
			'File size width is too small' => [ 300, 300, 150, false, null ],
			'File size height is too small' => [ 300, 150, 300, false, null ],
		];
	}

	/** @dataProvider provideGetImageContents */
	public function testGetImageContents(
		$fileContentsStatusGood, $thumbnailStatusGood, $thumbnailContentsStatusGood, $thumbnailMimeTypeStatusGood,
		$fileObjectClassName, $fileMimeType, $statsdBucketName, $expectStatusIsGood, $expectStatusIsOkay
	) {
		// Expect that the StatsdDataFactoryInterface::increment is called or not called
		// depending on $shouldIncrementStatsd
		$mockStatsdInterface = $this->createMock( StatsdDataFactoryInterface::class );
		$mockStatsdInterface->expects( $this->exactly( intval( strlen( $statsdBucketName ) !== 0 ) ) )
			->method( 'increment' )
			->with( $statsdBucketName );
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
				$mockStatsdInterface,
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
		} elseif ( $expectStatusIsOkay ) {
			$this->assertStatusOK( $actualStatus );
			$this->assertSame(
				$fileMimeType,
				$actualStatus->getMimeType(),
				'The status is okay, but no mime type is specified for the image'
			);
		} else {
			$this->assertStatusNotOK( $actualStatus );
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
				// The expected bucket name provided to the StatsdDataFactoryInterface::increment call. Specify an
				// empty string to expect no call.
				'',
				// Should the status returned by ::getImageContents be good (::isGood returns true)?
				true,
				// Should the status returned by ::getImageContents be okay (::isOK returns true)?
				false,
			],
			'ArchivedFile where source file contents collection failed' => [
				false, null, null, null, ArchivedFile::class, 'image/jpeg',
				'MediaModeration.PhotoDNAServiceProvider.Execute.RuntimeException', false, false,
			],
			'ArchivedFile where source file is not supported' => [
				false, null, null, null, ArchivedFile::class, 'image/svg',
				'MediaModeration.PhotoDNAServiceProvider.Execute.RuntimeException', false, false,
			],
			'File where no ThumbnailImage was generated but has file contents' => [
				true, false, null, null, File::class, 'image/jpeg',
				'MediaModeration.PhotoDNAServiceProvider.Execute.SourceFileUsedForFileObject', false, true,
			],
			'File where no ThumbnailImage was generated and no file contents' => [
				false, false, null, null, File::class, 'image/jpeg',
				'MediaModeration.PhotoDNAServiceProvider.Execute.RuntimeException', false, false,
			],
			'File where thumbnail contents failed but has file contents' => [
				true, true, false, false, File::class, 'image/jpeg',
				'MediaModeration.PhotoDNAServiceProvider.Execute.SourceFileUsedForFileObject', false, true,
			],
			'File where thumbnail contents succeeds' => [
				null, true, true, true, File::class, 'image/jpeg', '', true, true,
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
