<?php

namespace MediaWiki\Extension\MediaModeration\Services;

use ArchivedFile;
use File;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\InsertQueryBuilder;
use Wikimedia\Rdbms\UpdateQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseManager
 * @group MediaModeration
 */
class MediaModerationDatabaseManagerTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	/** @dataProvider provideFileClasses */
	public function testInsertFileIntoScanTableDoesNothingOnExistingFile( $fileClass ) {
		$mockMediaModerationDatabaseLookup = $this->createMock( MediaModerationDatabaseLookup::class );
		$mockMediaModerationDatabaseLookup->expects( $this->once() )
			->method( 'fileExistsInScanTable' )
			->willReturn( true );
		$mockDb = $this->createMock( IDatabase::class );
		$mockDb->expects( $this->never() )
			->method( 'newInsertQueryBuilder' );
		/** @var MediaModerationDatabaseManager $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( MediaModerationDatabaseManager::class, [
			'mediaModerationDatabaseLookup' => $mockMediaModerationDatabaseLookup,
			'dbw' => $mockDb,
		] );
		/** @var File|ArchivedFile $mockFile */
		$mockFile = $this->createMock( $fileClass );
		$objectUnderTest->insertFileToScanTable( $mockFile );
	}

	public static function provideFileClasses() {
		return [
			'Passing an ArchivedFile object to the method under test' => [ ArchivedFile::class ],
			'Passing an File object to the method under test' => [ File::class ],
		];
	}

	/** @dataProvider provideFileClasses */
	public function testInsertFileIntoScanTableInsertsAFile( $fileClass ) {
		$mockDb = $this->createMock( IDatabase::class );
		// Mock the InsertQueryBuilder to expect that the ::execute method is called.
		$insertQueryBuilderMock = $this->getMockBuilder( InsertQueryBuilder::class )
			->onlyMethods( [ 'execute' ] )
			->setConstructorArgs( [ $mockDb ] )
			->getMock();
		$mockDb->expects( $this->once() )
			->method( 'newInsertQueryBuilder' )
			->willReturn( $insertQueryBuilderMock );
		// Mock that the file doesn't exist in the table.
		$mockMediaModerationDatabaseLookup = $this->createMock( MediaModerationDatabaseLookup::class );
		$mockMediaModerationDatabaseLookup->expects( $this->once() )
			->method( 'fileExistsInScanTable' )
			->willReturn( false );
		// Create the object under test.
		/** @var MediaModerationDatabaseManager $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( MediaModerationDatabaseManager::class, [
			'mediaModerationDatabaseLookup' => $mockMediaModerationDatabaseLookup,
			'dbw' => $mockDb,
		] );
		// Create a mock file.
		/** @var File|ArchivedFile $mockFile */
		$mockFile = $this->createMock( $fileClass );
		$mockFile->method( 'getSha1' )->willReturn( 'abcdef1234' );
		// Call the method under test.
		$objectUnderTest->insertFileToScanTable( $mockFile );
		// Expect that the mock InsertQueryBuilder has the expected ::getQueryInfo result.
		$this->assertArrayEquals(
			[
				'table' => 'mediamoderation_scan',
				'rows' => [ [ 'mms_sha1' => $mockFile->getSha1() ] ],
				'upsert' => false,
				'set' => [],
				'uniqueIndexFields' => [],
				'options' => [],
				'caller' => 'MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseManager' .
					'::insertToScanTableInternal',
			],
			$insertQueryBuilderMock->getQueryInfo(),
			true,
			true,
			'The insert query performed was not as expected.'
		);
	}

	/** @dataProvider provideIsMatch */
	public function testUpdateMatchStatus( $isMatch, $fileClass ) {
		$mockDb = $this->createMock( IDatabase::class );
		// Create a mock UpdateQueryBuilder and expect that the ::execute method is called.
		$mockUpdateQueryBuilder = $this->getMockBuilder( UpdateQueryBuilder::class )
			->setConstructorArgs( [ $mockDb ] )
			->onlyMethods( [ 'execute' ] )
			->getMock();
		$mockUpdateQueryBuilder->expects( $this->once() )->method( 'execute' );
		// Mock the DB to return the mock UpdateQueryBuilder
		$mockDb->expects( $this->once() )->method( 'newUpdateQueryBuilder' )
			->willReturn( $mockUpdateQueryBuilder );
		// Create a mock file with a pre-defined SHA-1
		/** @var File|ArchivedFile $mockFile */
		$mockFile = $this->createMock( $fileClass );
		$mockFile->method( 'getSha1' )->willReturn( 'abcdef12345' );
		// Create the object under test.
		$objectUnderTest = $this->getMockBuilder( MediaModerationDatabaseManager::class )
			->onlyMethods( [ 'insertFileToScanTable' ] )
			->setConstructorArgs( [
				'dbw' => $mockDb,
				'mediaModerationDatabaseLookup' => $this->createMock( MediaModerationDatabaseLookup::class ),
			] )
			->getMock();
		// Expect that ::insertFileToScanTable is called once.
		$objectUnderTest->expects( $this->once() )
			->method( 'insertFileToScanTable' )
			->with( $mockFile );
		// Mock the current time to keep it constant for assertions later in the test.
		$fakeTimestamp = ConvertibleTimestamp::now();
		ConvertibleTimestamp::setFakeTime( '20230405060708' );
		// Call the method under test
		$objectUnderTest->updateMatchStatus( $mockFile, $isMatch );
		// Assert that the correct query was made
		$this->assertArrayEquals(
			[
				'table' => 'mediamoderation_scan',
				'set' => [
					'mms_is_match' => $isMatch ? 1 : 0,
					'mms_last_checked' => '20230405',
				],
				'conds' => [ 'mms_sha1' => $mockFile->getSha1() ],
				'options' => [],
				'caller' => 'MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseManager' .
					'::updateMatchStatus',
			],
			$mockUpdateQueryBuilder->getQueryInfo(),
			true,
			true,
			'The update query that was performed was not as expected.'
		);
		// Reset to remove the fake time.
		ConvertibleTimestamp::setFakeTime( false );
	}

	public static function provideIsMatch() {
		return [
			'Is not a match when passed a File object' => [ false, File::class ],
			'Is a match when passed a File object' => [ true, File::class ],
			'Is not a match when passed a ArchivedFile object' => [ false, ArchivedFile::class ],
			'Is a match when passed a ArchivedFile object' => [ true, ArchivedFile::class ],
		];
	}
}
