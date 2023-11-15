<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Services;

use ArchivedFile;
use Error;
use File;
use MediaHandler;
use MediaHandlerFactory;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseManager;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor
 * @group MediaModeration
 */
class MediaModerationFileProcessorTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	/** @dataProvider provideTestInsertFile */
	public function testInsertFile( $canScanFileResult, $expectsDatabaseInsert, $fileClass ) {
		$mediaModerationDatabaseManager = $this->createMock( MediaModerationDatabaseManager::class );
		// Mock the database manager to either expect or not expect
		// that ::insertFileToScanTable is called (depending on $expectsDatabaseInsert).
		/** @var File|ArchivedFile $fileObject */
		$fileObject = $this->createMock( $fileClass );
		$mediaModerationDatabaseManager->expects( $this->exactly( $expectsDatabaseInsert ? 1 : 0 ) )
			->method( 'insertFileToScanTable' )
			->with( $fileObject );
		// Get the object under test.
		$objectUnderTest = $this->getMockBuilder( MediaModerationFileProcessor::class )
			->setConstructorArgs( [
				$mediaModerationDatabaseManager,
				$this->createMock( MediaHandlerFactory::class ),
				$this->createMock( LoggerInterface::class )
			] )
			->onlyMethods( [ 'canScanFile' ] )
			->getMock();
		// ::canScanFile should always be called.
		$objectUnderTest->expects( $this->once() )
			->method( 'canScanFile' )
			->with( $fileObject )
			->willReturn( $canScanFileResult );
		// Call the method under test.
		$objectUnderTest->insertFile( $fileObject );
	}

	public static function provideTestInsertFile() {
		return [
			'::canScanFile returns false for a File object' => [ false, false, File::class ],
			'::canScanFile returns true for a File object' => [ true, true, File::class ],
			'::canScanFile returns false for a ArchivedFile object' => [ false, false, ArchivedFile::class ],
			'::canScanFile returns true for a ArchivedFile object' => [ true, true, ArchivedFile::class ],
		];
	}

	/** @dataProvider provideCanScanFile */
	public function testCanScanFile( $mimeType, $canRenderResult, $expectedReturnValue ) {
		// Create a mock file
		$mockFile = $this->createMock( File::class );
		$mockFile->method( 'canRender' )
			->willReturn( $canRenderResult );
		$mockFile->method( 'getMimeType' )
			->willReturn( $mimeType );
		$mockFile->method( 'getSha1' )
			->willReturn( 'abc1234' );
		$mockLogger = $this->createMock( LoggerInterface::class );
		if ( $expectedReturnValue ) {
			// If the expected return value is true, then the logger ::debug
			// method should not have been called.
			$mockLogger->expects( $this->never() )
				->method( 'debug' );
		} else {
			// If the expected return value is false, then the logger ::debug
			// method should have been called.
			$mockLogger->expects( $this->once() )
				->method( 'debug' )
				->with(
					'File with SHA-1 {sha1} cannot be scanned by PhotoDNA',
					[ 'sha1' => $mockFile->getSha1() ]
				);
		}
		// Get the object under test.
		$objectUnderTest = $this->newServiceInstance( MediaModerationFileProcessor::class, [
			'logger' => $mockLogger
		] );
		$this->assertSame(
			$expectedReturnValue,
			$objectUnderTest->canScanFile( $mockFile ),
			'::canScanFile did not return the expected value.'
		);
	}

	public static function provideCanScanFile() {
		return [
			'Mime type not supported and but can render' => [ 'image/svg', true, true ],
			'Mime type supported' => [ 'image/gif', false, true ],
			'Mime type not supported and cannot render' => [ 'text/plain', false, false ],
		];
	}

	public function testCanScanFileForArchivedFileWithSupportedMimeType() {
		// Create a mock file
		$mockFile = $this->createMock( ArchivedFile::class );
		$mockFile->method( 'getMimeType' )
			->willReturn( 'image/jpeg' );
		// Get the object under test.
		$objectUnderTest = $this->newServiceInstance( MediaModerationFileProcessor::class, [] );
		$this->assertSame(
			true,
			$objectUnderTest->canScanFile( $mockFile ),
			'::canScanFile did not return the expected value for an ArchivedFile with image/jpeg mime type.'
		);
	}

	/** @dataProvider provideCanScanFileForArchivedFileWithUnsupportedMimeType */
	public function testCanScanFileForArchivedFileWithUnsupportedMimeType(
		$mediaHandler, $fileExists, $expectedReturnResult
	) {
		// Create a mock file
		$mockFile = $this->createMock( ArchivedFile::class );
		$mockFile->method( 'getMimeType' )
			->willReturn( 'image/svg' );
		$mockFile->method( 'exists' )
			->willReturn( $fileExists );
		// Create a mock MediaHandlerFactory that no defined handler for the 'image/svg'
		$mediaHandlerFactory = $this->createMock( MediaHandlerFactory::class );
		$mediaHandlerFactory->method( 'getHandler' )
			->with( 'image/svg' )
			->willReturn( $mediaHandler );
		// Get the object under test.
		$objectUnderTest = $this->newServiceInstance( MediaModerationFileProcessor::class, [
			'mediaHandlerFactory' => $mediaHandlerFactory
		] );
		$this->assertSame(
			$expectedReturnResult,
			$objectUnderTest->canScanFile( $mockFile ),
			'::canScanFile did not return the expected value for an ArchivedFile.'
		);
	}

	public static function provideCanScanFileForArchivedFileWithUnsupportedMimeType() {
		return [
			'No handler defined for the mime type' => [ false, true, false ],
			'No handler defined for the mime type and file does not exist' => [ false, false, false ],
		];
	}

	/** @dataProvider provideCanScanFileForArchivedFileWithValidCanRenderCall */
	public function testCanScanFileForArchivedFileWithValidCanRenderCall(
		$canRenderResult, $fileExists, $expectedReturnResult
	) {
		$mediaHandlerMock = $this->createMock( MediaHandler::class );
		// Simulate a method missing exception (which uses the Error class as the error object that is thrown).
		$mediaHandlerMock->method( 'canRender' )
			->willReturn( $canRenderResult );
		$this->testCanScanFileForArchivedFileWithUnsupportedMimeType(
			$mediaHandlerMock, $fileExists, $expectedReturnResult
		);
	}

	public static function provideCanScanFileForArchivedFileWithValidCanRenderCall() {
		return [
			'MediaHandler::canRender returns true and ArchivedFile::exist returns true' => [ true, true, true ],
			'MediaHandler::canRender returns true and ArchivedFile::exist returns false' => [ true, false, false ],
			'MediaHandler::canRender returns false and ArchivedFile::exist returns true' => [ false, true, false ],
			'MediaHandler::canRender returns false and ArchivedFile::exist returns false' => [ false, false, false ],
		];
	}

	public function testCanScanFileForArchivedFileWithHandlerThatThrowsException() {
		$canRenderException = new Error( 'Call to undefined method ArchivedFile::testMethod.' );
		$mediaHandlerMock = $this->createMock( MediaHandler::class );
		// Simulate a method missing exception (which uses the Error class as the error object that is thrown).
		$mediaHandlerMock->method( 'canRender' )
			->willThrowException( $canRenderException );
		// Create a mock file
		$mockFile = $this->createMock( ArchivedFile::class );
		$mockFile->method( 'getMimeType' )
			->willReturn( 'image/svg' );
		// Create a mock MediaHandlerFactory that no defined handler for the 'image/svg'
		$mediaHandlerFactory = $this->createMock( MediaHandlerFactory::class );
		$mediaHandlerFactory->method( 'getHandler' )
			->with( 'image/svg' )
			->willReturn( $mediaHandlerMock );
		// Create a mock Logger.
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->with(
				'Call to MediaHandler::canRender with an ArchivedFile did not work for handler {handlerclass}',
				[
					'handlerclass' => get_class( $mediaHandlerMock ),
					'exception' => $canRenderException
				]
			);
		// Get the object under test.
		$objectUnderTest = $this->newServiceInstance( MediaModerationFileProcessor::class, [
			'mediaHandlerFactory' => $mediaHandlerFactory,
			'logger' => $mockLogger,
		] );
		$this->assertSame(
			false,
			$objectUnderTest->canScanFile( $mockFile ),
			'::canScanFile did not return the expected value for an ArchivedFile when MediaHandler::' .
			'canRender throws an exception.'
		);
	}
}
