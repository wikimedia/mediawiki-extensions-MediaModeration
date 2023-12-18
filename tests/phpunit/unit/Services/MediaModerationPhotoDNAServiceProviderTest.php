<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Services;

use ArchivedFile;
use File;
use FileBackend;
use MediaTransformError;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\Exception\RuntimeException;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationPhotoDNAServiceProvider;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use ThumbnailImage;
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
		// Call the method under test
		/** @var MediaModerationPhotoDNAServiceProvider $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationPhotoDNAServiceProvider::class,
			[
				'options' => new ServiceOptions(
					MediaModerationPhotoDNAServiceProvider::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'fileBackend' => $fileBackendMock
			]
		);
		$checkStatus = $objectUnderTest->check( $mockFile );
		$this->assertStatusNotOK(
			$checkStatus,
			'::check should return a fatal status on a thrown RuntimeException.'
		);
	}

	public function testGetThumbnailForFileForArchivedFileObject() {
		// Expect a RuntimeException if an ArchivedFile class is provided, as this
		// is currently not supported.
		$this->expectException( RuntimeException::class );
		// Get and call the method under test with an ArchivedFile instance.
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationPhotoDNAServiceProvider::class,
			[
				'options' => new ServiceOptions(
					MediaModerationPhotoDNAServiceProvider::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				)
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->getThumbnailForFile( $this->createMock( ArchivedFile::class ) );
	}

	/** @dataProvider provideGetThumbnailForFile */
	public function testGetThumbnailForFile( $thumbnailClassName ) {
		$this->expectException( RuntimeException::class );
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
		// Call the method under test
		/** @var MediaModerationPhotoDNAServiceProvider $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationPhotoDNAServiceProvider::class,
			[
				'options' => new ServiceOptions(
					MediaModerationPhotoDNAServiceProvider::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				)
			]
		);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->getThumbnailForFile( $mockFile );
	}

	public static function provideGetThumbnailForFile() {
		return [
			'::transform returns false' => [ false ],
			'::transform returns MediaTransformError' => [ MediaTransformError::class ],
			'::transform returns an unexpected class' => [ RuntimeException::class ],
			'::transform returns ThumbnailImage with ::hasFile as false' => [ ThumbnailImage::class ],
		];
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
		// Get the object under test
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationPhotoDNAServiceProvider::class,
			[
				'options' => new ServiceOptions(
					MediaModerationPhotoDNAServiceProvider::CONSTRUCTOR_OPTIONS,
					new HashConfig( self::CONSTRUCTOR_OPTIONS_DEFAULTS )
				),
				'mimeAnalyzer' => $mockMimeAnalyzer
			]
		);
		// Call the method under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			$expectedReturnValue,
			$objectUnderTest->getThumbnailMimeType( $mockThumbnailImage ),
			'Return value of ::getThumbnailMimeType was not as expected.'
		);
	}

	public static function provideGetThumbnailMimeType() {
		return [
			'Thumbnail type is got from thumbnail extension' => [ 'image/jpeg', '', 'image/jpeg' ],
			'Thumbnail type is got from guessing using the file contents' => [ null, 'image/png', 'image/png' ],
		];
	}

	/** @dataProvider provideGetThumbnailMimeTypeOnException */
	public function testGetThumbnailMimeTypeOnException( $fromExtensionResult, $guessFromContentsResult ) {
		$this->expectException( RuntimeException::class );
		$this->testGetThumbnailMimeType( $fromExtensionResult, $guessFromContentsResult, 'unused' );
	}

	public static function provideGetThumbnailMimeTypeOnException() {
		return [
			'No mime type from either methods' => [ null, '' ],
			'Unsupported mime type from extension method' => [ 'image/svg', '' ],
			'Unsupported mime type from guess method' => [ null, 'image/svg' ],
		];
	}
}
