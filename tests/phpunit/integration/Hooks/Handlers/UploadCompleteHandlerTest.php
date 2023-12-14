<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Hooks\Handlers;

use File;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\MediaModeration\Hooks\Handlers\UploadCompleteHandler;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWikiIntegrationTestCase;
use UploadBase;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Hooks\Handlers\UploadCompleteHandler
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseManager
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup
 * @group MediaModeration
 * @group Database
 */
class UploadCompleteHandlerTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->tablesUsed = [
			'mediamoderation_scan'
		];
	}

	/**
	 * Tests that an uploaded file is added to the scan table and
	 * that the lookup service says it was added to the table.
	 */
	public function testFileAddedToScanTableLoop() {
		$objectUnderTest = new UploadCompleteHandler(
			$this->getServiceContainer()->get( 'MediaModerationFileProcessor' ),
			new HashConfig( [ 'MediaModerationAddToScanTableOnUpload' => true ] )
		);
		// Mock a file to define a SHA-1
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
		// Check that the file exists according to the lookup service.
		/** @var MediaModerationDatabaseLookup $mediaModerationDatabaseLookup */
		$mediaModerationDatabaseLookup = $this->getServiceContainer()->get( 'MediaModerationDatabaseLookup' );
		$this->assertTrue(
			$mediaModerationDatabaseLookup->fileExistsInScanTable( $mockFile ),
			'The mock file should have been added to the scan table.'
		);
	}
}
