<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Hooks\Handlers;

use MediaWiki\Config\HashConfig;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\MediaModeration\Hooks\Handlers\UploadCompleteHandler;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor;
use MediaWiki\FileRepo\File\File;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UploadBase;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Hooks\Handlers\UploadCompleteHandler
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseManager
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup
 * @covers \MediaWiki\Extension\MediaModeration\Deferred\InsertFileOnUploadUpdate
 * @covers \MediaWiki\Extension\MediaModeration\Job\MediaModerationInsertFileOnUploadJob
 * @group MediaModeration
 * @group Database
 */
class UploadCompleteHandlerTest extends MediaWikiIntegrationTestCase {

	/**
	 * Tests that an uploaded file is added to the scan table and
	 * that the lookup service says it was added to the table.
	 */
	public function testFileAddedToScanTableLoop() {
		$objectUnderTest = new UploadCompleteHandler(
			$this->getServiceContainer()->get( 'MediaModerationFileProcessor' ),
			$this->getServiceContainer()->get( 'MediaModerationDatabaseLookup' ),
			$this->getServiceContainer()->get( 'MediaModerationEmailer' ),
			$this->getServiceContainer()->getDBLoadBalancerFactory(),
			new HashConfig( [ 'MediaModerationAddToScanTableOnUpload' => true ] )
		);
		// Create a mock File object
		$mockFile = $this->createMock( File::class );
		$mockFile->method( 'getMimeType' )
			->willReturn( 'image/gif' );
		$mockFile->method( 'getMediaType' )
			->willReturn( MEDIATYPE_BITMAP );
		$mockFile->method( 'getSha1' )
			->willReturn( 'syrtqda72zc7dpjqeukz3d686doficu' );
		$mockUploadBase = $this->createMock( UploadBase::class );
		$mockUploadBase->method( 'getLocalFile' )
			->willReturn( $mockFile );
		// Simulate a file upload.
		$objectUnderTest->onUploadComplete( $mockUploadBase );
		// Wait for the deferred update to run.
		DeferredUpdates::doUpdates();
		// Check that the file exists according to the lookup service.
		/** @var MediaModerationDatabaseLookup $mediaModerationDatabaseLookup */
		$mediaModerationDatabaseLookup = $this->getServiceContainer()->get( 'MediaModerationDatabaseLookup' );
		$this->assertTrue(
			$mediaModerationDatabaseLookup->fileExistsInScanTable( $mockFile ),
			'The mock file should have been added to the scan table.'
		);
	}

	public function testFileAddedToScanTableLoopOnDeferredUpdateFailure() {
		// Mock that an error is thrown when the deferred update tries to insert the file.
		// The job will use MediaModerationDatabaseManager to insert the file, so it does not use this mock
		// implementation and will therefore succeed.
		$mockMediaModerationFileProcessor = $this->createMock( MediaModerationFileProcessor::class );
		$mockMediaModerationFileProcessor->method( 'insertFile' )
			->willThrowException( new RuntimeException() );
		$mockMediaModerationFileProcessor->method( 'canScanFile' )
			->willReturn( true );

		$objectUnderTest = new UploadCompleteHandler(
			$mockMediaModerationFileProcessor,
			$this->getServiceContainer()->get( 'MediaModerationDatabaseLookup' ),
			$this->getServiceContainer()->get( 'MediaModerationEmailer' ),
			$this->getServiceContainer()->getDBLoadBalancerFactory(),
			new HashConfig( [ 'MediaModerationAddToScanTableOnUpload' => true ] )
		);
		// Create a mock File object
		$mockFile = $this->createMock( File::class );
		$mockFile->method( 'getMimeType' )
			->willReturn( 'image/gif' );
		$mockFile->method( 'getMediaType' )
			->willReturn( MEDIATYPE_BITMAP );
		$mockFile->method( 'getSha1' )
			->willReturn( 'syrtqda72zc7dpjqeukz3d686doficu' );
		$mockUploadBase = $this->createMock( UploadBase::class );
		$mockUploadBase->method( 'getLocalFile' )
			->willReturn( $mockFile );

		// Stop the RuntimeException from being logged in the exception log. Without this the test will fail to
		// pass in MediaWiki CI because the mw-error.log should be empty.
		$this->setLogger( 'exception', $this->createMock( LoggerInterface::class ) );

		// Simulate a file upload, expecting that the file upload will fail with an exception.
		$exceptionThrown = false;
		try {
			$objectUnderTest->onUploadComplete( $mockUploadBase );
			DeferredUpdates::doUpdates();
		} catch ( RuntimeException ) {
			$exceptionThrown = true;
		}
		$this->assertTrue( $exceptionThrown );

		// Expect that the deferred update did not run
		/** @var MediaModerationDatabaseLookup $mediaModerationDatabaseLookup */
		$mediaModerationDatabaseLookup = $this->getServiceContainer()->get( 'MediaModerationDatabaseLookup' );
		$this->assertFalse( $mediaModerationDatabaseLookup->fileExistsInScanTable( $mockFile ) );

		// Expect that a job was inserted after the deferred update failure and then execute it.
		$this->runJobs( [ 'numJobs' => 1 ], [ 'type' => 'mediaModerationInsertFileOnUploadJob' ] );

		// Expect that the file exists in the mediamoderation_scan table after the job execution.
		$this->assertTrue( $mediaModerationDatabaseLookup->fileExistsInScanTable( $mockFile ) );
	}
}
