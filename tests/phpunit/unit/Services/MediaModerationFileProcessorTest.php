<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Services;

use File;
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
	public function testInsertFile( $canScanFileResult, $expectsDatabaseInsert ) {
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mediaModerationDatabaseManager = $this->createMock( MediaModerationDatabaseManager::class );
		// Mock the database manager to either expect or not expect
		// that ::insertFileToScanTable is called (depending on $expectsDatabaseInsert).
		$fileObject = $this->createMock( File::class );
		$mediaModerationDatabaseManager->expects( $this->exactly( $expectsDatabaseInsert ? 1 : 0 ) )
			->method( 'insertFileToScanTable' )
			->with( $fileObject );
		// Get the object under test.
		$objectUnderTest = $this->getMockBuilder( MediaModerationFileProcessor::class )
			->setConstructorArgs( [
				$mediaModerationDatabaseManager,
				$mockLogger
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
			'::canScanFile returns false for the File' => [ false, false ],
			'::canScanFile returns true for the File' => [ true, true ],
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
}
