<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Maintenance;

use File;
use LocalRepo;
use MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileFactory;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor;
use MediaWiki\FileRepo\File\FileSelectQueryBuilder;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\Expression;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable
 * @group MediaModeration
 */
class ImportExistingFilesToScanTableTest extends MediaWikiUnitTestCase {

	use MockServiceDependenciesTrait;

	public function testGetUpdateKey() {
		// Verifies that the update key does not change without deliberate meaning, as it could
		// cause the script to be unnecessarily re-run on a new call to update.php.
		$objectUnderTest = new ImportExistingFilesToScanTable();
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			'MediaWiki\\Extension\\MediaModeration\\Maintenance\\ImportExistingFilesToScanTable',
			$objectUnderTest->getUpdateKey(),
			'::getUpdateKey did not return the expected key.'
		);
	}

	/** @dataProvider provideGetFileSelectQueryBuilder */
	public function testGetFileSelectQueryBuilder(
		$table, $previousBatchFinalTimestamp, $raisedBatchSize, $expectedTimestampField,
		$expectedQueryBuilderQueryInfoArray
	) {
		// Get the object under test.
		$objectUnderTest = $this->getMockBuilder( ImportExistingFilesToScanTable::class )
			->onlyMethods( [ 'getTemporaryBatchSize' ] )
			->getMock();
		if ( $raisedBatchSize ) {
			// If a raised batch size is defined, then $shouldRaiseBatchSize will be true and as such
			// ::getTemporaryBatchSize should be called.
			$objectUnderTest->expects( $this->once() )
				->method( 'getTemporaryBatchSize' )
				->willReturn( $raisedBatchSize );
		} else {
			// If a raised batch size is not defined, then $shouldRaiseBatchSize will be false and as such
			// ::getTemporaryBatchSize should not be called.
			$objectUnderTest->expects( $this->never() )
				->method( 'getTemporaryBatchSize' );
		}
		$mockExpressionObject = $this->createMock( Expression::class );
		// Mock the dbr such that ::expr returns the mock Expression object.
		$mockDbr = $this->createMock( IReadableDatabase::class );
		$mockDbr->expects( $previousBatchFinalTimestamp ? $this->once() : $this->never() )
			->method( 'expr' )
			->with( $expectedTimestampField, '>=', $previousBatchFinalTimestamp )
			->willReturn( $mockExpressionObject );
		$mockDbr->expects( $previousBatchFinalTimestamp ? $this->once() : $this->never() )
			->method( 'timestamp' )
			->with( $previousBatchFinalTimestamp )
			->willReturn( $previousBatchFinalTimestamp );
		// Create a mock LocalRepo that returns a mock DB from ::getReplicaDb
		$mockLocalRepo = $this->createMock( LocalRepo::class );
		$mockLocalRepo->method( 'getReplicaDB' )
			->willReturn( $this->createMock( IReadableDatabase::class ) );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->dbr = $mockDbr;
		$objectUnderTest->mediaModerationFileLookup = $this->newServiceInstance(
			MediaModerationFileLookup::class, [
				'localRepo' => $mockLocalRepo,
			]
		);
		// Call the method under test.
		/** @var FileSelectQueryBuilder $fileSelectQueryBuilder */
		$fileSelectQueryBuilder = $objectUnderTest->getFileSelectQueryBuilder(
			$table, $previousBatchFinalTimestamp, !( $raisedBatchSize === false )
		);
		// Add the mock Expression object to the expected conditions.
		$actualQueryInfo = $fileSelectQueryBuilder->getQueryInfo();
		if ( $previousBatchFinalTimestamp ) {
			$expectedQueryBuilderQueryInfoArray['conds'][] = $mockExpressionObject;
		}
		$this->assertArrayContains(
			$expectedQueryBuilderQueryInfoArray,
			$actualQueryInfo,
			'::getFileSelectQueryBuilder did not return the expected FileSelectQueryBuilder object.'
		);
	}

	public static function provideGetFileSelectQueryBuilder() {
		return [
			'The table is the image table' => [
				'image',
				'',
				false,
				'img_timestamp',
				[
					'tables' => [ 'image' ],
					'conds' => [],
					'options' => [ 'LIMIT' => 200, 'ORDER BY' => [ 'img_timestamp ASC' ] ]
				]
			],
			'The table is the image table with temporarily raised limit' => [
				'image',
				'',
				301,
				'img_timestamp',
				[
					'tables' => [ 'image' ],
					'conds' => [],
					'options' => [ 'LIMIT' => 301, 'ORDER BY' => [ 'img_timestamp ASC' ] ]
				]
			],
			'The table is the oldimage table with a previous batch final timestamp' => [
				'oldimage',
				'20230605040302',
				false,
				'oi_timestamp',
				[
					'tables' => [ 'oldimage' ],
					// The mock Expression object is added by the test, as we do not have the ability
					// to create mock objects there (as we are in a static method).
					'conds' => [],
					'options' => [ 'LIMIT' => 200, 'ORDER BY' => [ 'oi_timestamp ASC' ] ]
				]
			],
			'The table is the filearchive table' => [
				'filearchive',
				'',
				false,
				'fa_timestamp',
				[
					'tables' => [ 'filearchive' ],
					'conds' => [],
					'options' => [ 'LIMIT' => 200, 'ORDER BY' => [ 'fa_timestamp ASC' ] ]
				]
			],
			'The table is the filearchive table with a previous batch final timestamp and temporarily raised limit' => [
				'filearchive',
				'20200605040302',
				235,
				'fa_timestamp',
				[
					'tables' => [ 'filearchive' ],
					'conds' => [],
					'options' => [ 'LIMIT' => 235, 'ORDER BY' => [ 'fa_timestamp ASC' ] ]
				]
			],
		];
	}

	/** @dataProvider provideGetTemporaryBatchSize */
	public function testGetTemporaryBatchSize(
		$defaultBatchSize, $numberOfFilesWithThisTimestamp, $expectedTemporaryBatchSize
	) {
		// Get a mock FileSelectQueryBuilder, that expects that ::fetchField is called and returns
		// $numberOfFilesWithThisTimestamp on the call to this method.
		$mockFileSelectQueryBuilder = $this->getMockBuilder( FileSelectQueryBuilder::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'fetchField' ] )
			->getMock();
		$mockFileSelectQueryBuilder->expects( $this->once() )
			->method( 'fetchField' )
			->willReturn( $numberOfFilesWithThisTimestamp );
		// Add some fake fields that would be cleared by the call to FileSelectQueryBuilder::clearFields
		// in the method under test.
		$mockFileSelectQueryBuilder->fields( [ 'test', 'testing2' ] );
		// Get the object under test.
		$objectUnderTest = $this->getMockBuilder( ImportExistingFilesToScanTable::class )
			->onlyMethods( [ 'output' ] )
			->getMock();
		// Work out if the raised batch size should be used for the mock below.
		$shouldUseRaisedBatchSize = ( $expectedTemporaryBatchSize > ( $defaultBatchSize ?? 200 ) );
		// Expect that the ::output method is called if a raised batch size is used (i.e. one over the normal batch
		// size). If the raised batch size isn't used, then expect the ::output method is not called.
		$objectUnderTest->expects( $shouldUseRaisedBatchSize ? $this->once() : $this->never() )
			->method( 'output' )
			->with(
				"Temporarily raised the batch size to $expectedTemporaryBatchSize due to files with the " .
				'same upload timestamp. This is done to prevent an infinite loop. Consider raising the batch size ' .
				"to avoid this.\n"
			);
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		// Set the normal batch size to a custom value for the test.
		$objectUnderTest->mBatchSize = $defaultBatchSize;
		$mockExpressionObject = $this->createMock( Expression::class );
		// Mock the dbr such that ::expr returns the mock Expression object.
		$mockDbr = $this->createMock( IReadableDatabase::class );
		$mockDbr->expects( $this->once() )
			->method( 'expr' )
			->with( 'img_timestamp', '=', '20230405060708' )
			->willReturn( $mockExpressionObject );
		$mockDbr->expects( $this->once() )
			->method( 'timestamp' )
			->with( '20230405060708' )
			->willReturn( '20230405060708' );
		$objectUnderTest->dbr = $mockDbr;
		// Verify the return value is as expected.
		$this->assertSame(
			$expectedTemporaryBatchSize,
			$objectUnderTest->getTemporaryBatchSize(
				$mockFileSelectQueryBuilder, 'img_timestamp', '20230405060708'
			),
			'::getTemporaryBatchSize did not return the expected temporary batch size'
		);
		// Check that the FileSelectQueryBuilder::getQueryInfo returns the expected query info (which tests
		// that the query performed was as expected).
		$this->assertArrayEquals(
			[
				'fields' => [ 'COUNT(*)' ],
				// The tables array will be empty as we created a mock FileSelectQueryBuilder with the constructor
				// disabled.
				'tables' => [],
				'conds' => [ $mockExpressionObject ],
				'caller' => 'MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable' .
					'::getTemporaryBatchSize',
				'options' => [],
				'join_conds' => [],
			],
			$mockFileSelectQueryBuilder->getQueryInfo(),
			true,
			true,
			'FileSelectQueryBuilder::getQueryInfo did not return the expected query info, suggesting that ' .
			'the query performed by ::getTemporaryBatchSize was not as expected.'
		);
	}

	public static function provideGetTemporaryBatchSize() {
		return [
			'Normal batch size is equal to the number of files with this timestamp' => [ 150, 150, 151 ],
			'Normal batch size is smaller than the number of files with this timestamp' => [ 30, 40, 41 ],
			'Normal batch size is larger than the number of files with this timestamp' => [ 40, 30, 40 ],
			'Normal batch size is null but number of files exceeds 200' => [ null, 250, 251 ],
			'Normal batch size is null but number of files is less than 200' => [ null, 100, 200 ],
		];
	}

	/** @dataProvider providePerformBatch */
	public function testPerformBatch(
		$previousBatchFinalTimestamp, $queryResult, $lastTimestamp, $batchSize, $expectedReturnArray
	) {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ImportExistingFilesToScanTable::class )
			->onlyMethods( [ 'getRowsForBatch' ] )
			->getMock();
		// Mock ::getRowsForBatch to return the mock query result.
		$objectUnderTest->method( 'getRowsForBatch' )
			->with( 'image', $previousBatchFinalTimestamp )
			->willReturn( [ $queryResult, $lastTimestamp, $batchSize ?? 200 ] );
		// Define mocks and expectations for the method calls in the foreach loop of ::performBatch.
		$getFileObjectForRowExpectedRows = [];
		$getFileObjectForRowReturnedFiles = [];
		$fileExistsInScanTableExpectedFiles = [];
		$fileExistsInScanTableReturnValues = [];
		$insertFileExpectedFiles = [];
		foreach ( $queryResult as $row ) {
			// Mock $mockMediaModerationFileFactory::getFileObjectForRow to expect the correct order of rows and
			// also return a mock File object for the associated call.
			$getFileObjectForRowExpectedRows[] = $row;
			$rowAsArray = (array)$row;
			$mockFile = $this->createMock( File::class );
			$mockFile->method( 'getSha1' )
				->willReturn( $rowAsArray['sha1'] );
			$mockFile->method( 'getTimestamp' )
				->willReturn( array_key_exists( 'timestamp', $rowAsArray ) ? $rowAsArray['timestamp'] : '' );
			$getFileObjectForRowReturnedFiles[] = $mockFile;
			if ( $mockFile->getSha1() ) {
				// Mock the MediaModerationDatabaseLookup::fileExistsInScanTable to return the value of the $row's
				// 'exists_in_scan_table' key (or by default false).
				$fileExistsInScanTableExpectedFiles[] = $mockFile;
				$doesFileExistInScanTable = array_key_exists( 'exists_in_scan_table', $rowAsArray ) &&
					$rowAsArray['exists_in_scan_table'];
				$fileExistsInScanTableReturnValues[] = $doesFileExistInScanTable;
				if ( !$doesFileExistInScanTable ) {
					// Expect that MediaModerationFileProcessor::insertFile is called for files which have a valid SHA-1
					// and for which value of the $row['exists_in_scan_table'] is false.
					$insertFileExpectedFiles[] = $mockFile;
				}
			}
		}
		// Apply the expectations and mocks for consecutive calls to the methods in the foreach loop.
		$mockMediaModerationFileFactory = $this->createMock( MediaModerationFileFactory::class );
		$mockMediaModerationFileFactory->expects( $this->exactly( count( $getFileObjectForRowExpectedRows ) ) )
			->method( 'getFileObjectForRow' )
			->willReturnCallback(
				function ( $row, $tbl ) use ( &$getFileObjectForRowExpectedRows, &$getFileObjectForRowReturnedFiles ) {
					$this->assertEquals( array_shift( $getFileObjectForRowExpectedRows ), $row );
					$this->assertSame( 'image', $tbl );
					return array_shift( $getFileObjectForRowReturnedFiles );
				}
			);
		$mockMediaModerationDatabaseLookup = $this->createMock( MediaModerationDatabaseLookup::class );
		$mockMediaModerationDatabaseLookup->expects( $this->exactly( count( $fileExistsInScanTableExpectedFiles ) ) )
			->method( 'fileExistsInScanTable' )
			->willReturnCallback(
				function ( $file ) use ( &$fileExistsInScanTableExpectedFiles, &$fileExistsInScanTableReturnValues ) {
					$this->assertSame( array_shift( $fileExistsInScanTableExpectedFiles ), $file );
					return array_shift( $fileExistsInScanTableReturnValues );
				}
			);
		$mockMediaModerationFileProcessor = $this->createMock( MediaModerationFileProcessor::class );
		$mockMediaModerationFileProcessor->expects( $this->exactly( count( $insertFileExpectedFiles ) ) )
			->method( 'insertFile' )
			->willReturnCallback( function ( $file ) use ( &$insertFileExpectedFiles ) {
				$this->assertSame( array_shift( $insertFileExpectedFiles ), $file );
			} );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mBatchSize = $batchSize;
		$objectUnderTest->mediaModerationDatabaseLookup = $mockMediaModerationDatabaseLookup;
		$objectUnderTest->mediaModerationFileProcessor = $mockMediaModerationFileProcessor;
		$objectUnderTest->mediaModerationFileFactory = $mockMediaModerationFileFactory;
		$this->assertArrayEquals(
			$expectedReturnArray,
			$objectUnderTest->performBatch( 'image', $previousBatchFinalTimestamp, false ),
			true,
			false,
			'Return array of ::performBatch was not as expected.'
		);
	}

	public static function providePerformBatch() {
		// The fake result rows are special as they contain information about
		// the File object that would be created by the mock implementation of
		// $mockMediaModerationFileFactory::getFileObjectForRow.
		return [
			'No rows returned by the query' => [
				'',
				new FakeResultWrapper( [] ),
				'',
				200,
				[ false, '' ],
			],
			'One row returned by the query with empty SHA-1' => [
				'',
				new FakeResultWrapper( [
					[ 'sha1' => '', 'exists_in_scan_table' => false ]
				] ),
				'',
				null,
				[ false, '' ],
			],
			'One row returned by the query with SHA-1 as false' => [
				'',
				new FakeResultWrapper( [
					[ 'sha1' => false, 'exists_in_scan_table' => false ]
				] ),
				'20230405060708',
				200,
				[ false, '20230405060708' ],
			],
			'Multiple rows returned by the query' => [
				'',
				new FakeResultWrapper( [
					[ 'sha1' => 'abc', 'exists_in_scan_table' => false ],
					[ 'sha1' => 'abc123', 'exists_in_scan_table' => true ],
					[ 'sha1' => 'abc12345', 'exists_in_scan_table' => true ]
				] ),
				'20230405060710',
				200,
				[ false, '20230405060710' ],
			],
			'Count of returned rows matches the batch size' => [
				'20230405060705',
				new FakeResultWrapper( [
					[ 'sha1' => 'abc', 'exists_in_scan_table' => false ],
					[ 'sha1' => 'abc123', 'exists_in_scan_table' => true ],
					[ 'sha1' => 'abc12345', 'exists_in_scan_table' => true ]
				] ),
				'20230405060710',
				3,
				[ true, '20230405060710' ],
			],
		];
	}

	/** @dataProvider provideGetRowsForBatch */
	public function testGetRowsForBatch(
		$previousBatchFinalTimestamp, IResultWrapper $queryResult, $expectedLastFileTimestamp
	) {
		$lastResultRow = null;
		if ( $queryResult->numRows() ) {
			// Get the last result object from $queryResult
			// if the query result has rows.
			$queryResult->seek( $queryResult->numRows() - 1 );
			$lastResultRow = $queryResult->fetchObject();
			$queryResult->rewind();
		}
		// Get the object under test.
		$objectUnderTest = $this->getMockBuilder( ImportExistingFilesToScanTable::class )
			->onlyMethods( [ 'getFileSelectQueryBuilder' ] )
			->getMock();
		// Mock the ::getFileSelectQueryBuilder result to return a mock FileSelectQueryBuilder that
		// returns the results wrapper provided by the data provider.
		$mockFileSelectQueryBuilder = $this->createMock( FileSelectQueryBuilder::class );
		$mockFileSelectQueryBuilder->expects( $this->once() )
			->method( 'caller' )
			->with(
				'MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable' .
				'::getRowsForBatch'
			)
			->willReturnSelf();
		$mockFileSelectQueryBuilder->expects( $this->once() )
			->method( 'fetchResultSet' )
			->willReturn( $queryResult );
		// Mock the mock FileSelectQueryBuilder to return the LIMIT in the ::getQueryInfo call
		$mockFileSelectQueryBuilder->method( 'getQueryInfo' )
			->willReturn( [ 'options' => [ 'LIMIT' => 150 ] ] );
		$objectUnderTest->method( 'getFileSelectQueryBuilder' )
			->with( 'image', $previousBatchFinalTimestamp, false )
			->willReturn( $mockFileSelectQueryBuilder );
		$mockMediaModerationFileFactory = $this->createMock( MediaModerationFileFactory::class );
		if ( $lastResultRow !== null ) {
			// Mock the MediaModerationFileFactory::getFileObjectForRow method to return the File object
			// for the last row, if the query result had at least one row.
			$mockFileObject = $this->createMock( File::class );
			$mockFileObject->method( 'getTimestamp' )
				->willReturn( $expectedLastFileTimestamp );
			$mockMediaModerationFileFactory->method( 'getFileObjectForRow' )
				->with( $lastResultRow, 'image' )
				->willReturn( $mockFileObject );
		}
		// Call the method under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mediaModerationFileFactory = $mockMediaModerationFileFactory;
		$this->assertArrayEquals(
			[ $queryResult, $expectedLastFileTimestamp, 150 ],
			$objectUnderTest->getRowsForBatch( 'image', $previousBatchFinalTimestamp ),
			true,
			false,
			'::getRowsForBatch did not return the expected array when no rows are returned by the ' .
			'FileSelectQueryBuilder::fetchResultSet call.'
		);
	}

	public static function provideGetRowsForBatch() {
		// The fake result rows are special as they contain information about
		// the File object that would be created by the mock implementation of
		// MediaModerationFileFactory::getFileObjectForRow.
		return [
			'No rows returned by the query' => [
				'20230506070808',
				new FakeResultWrapper( [] ),
				'20230506070808',
			],
			'One row returned by the query with no previous batch timestamp' => [
				'',
				new FakeResultWrapper( [ [ 'timestamp' => '20230506070809' ] ] ),
				'20230506070809',
			],
			'Multiple rows returned by the query' => [
				'',
				new FakeResultWrapper( [
					[ 'timestamp' => '20230405060701' ],
					[ 'timestamp' => '20230405060703' ],
					[ 'timestamp' => '20230405060710' ]
				] ),
				'20230405060710',
			],
		];
	}

	/** @dataProvider provideGetRowsForBatchWhenRowsAllHaveTheSameTimestamp */
	public function testGetRowsForBatchWhenRowsAllHaveTheSameTimestamp(
		$previousBatchFinalTimestamp, IResultWrapper $queryResult,
		IResultWrapper $secondQueryResult, $expectedLastFileTimestamp
	) {
		// Get the last result object from $queryResult
		$queryResult->seek( $queryResult->numRows() - 1 );
		$lastResultRow = $queryResult->fetchObject();
		$queryResult->rewind();
		// Get the last result object from $secondQueryResult
		$secondQueryResult->seek( $secondQueryResult->numRows() - 1 );
		$lastResultRowForSecondQuery = $secondQueryResult->fetchObject();
		$secondQueryResult->rewind();
		// Get the object under test.
		$objectUnderTest = $this->getMockBuilder( ImportExistingFilesToScanTable::class )
			->onlyMethods( [ 'getFileSelectQueryBuilder' ] )
			->getMock();
		// Mock the ::getFileSelectQueryBuilder result to return a mock FileSelectQueryBuilder that
		// returns the first and then the second result wrapper.
		$mockFileSelectQueryBuilder = $this->createMock( FileSelectQueryBuilder::class );
		$mockFileSelectQueryBuilder->expects( $this->exactly( 2 ) )
			->method( 'caller' )
			->with(
				'MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable' .
				'::getRowsForBatch'
			)
			->willReturnSelf();
		$mockFileSelectQueryBuilder->expects( $this->exactly( 2 ) )
			->method( 'fetchResultSet' )
			->willReturn( $queryResult, $secondQueryResult );
		// Mock the mock FileSelectQueryBuilder to return the LIMIT used in the query
		$mockFileSelectQueryBuilder->method( 'getQueryInfo' )
			->willReturn( [ 'options' => [ 'LIMIT' => $secondQueryResult->count() ] ] );
		$objectUnderTest->method( 'getFileSelectQueryBuilder' )
			->withConsecutive(
				[ 'image', $previousBatchFinalTimestamp, false ],
				[ 'image', $previousBatchFinalTimestamp, true ],
			)
			->willReturn( $mockFileSelectQueryBuilder );
		// Mock the MediaModerationFileFactory::getFileObjectForRow method to return the
		// File objects for the last row in the first and second queries.
		$mockMediaModerationFileFactory = $this->createMock( MediaModerationFileFactory::class );
		$mockFileObject = $this->createMock( File::class );
		$mockFileObject->method( 'getTimestamp' )
			->willReturn( $previousBatchFinalTimestamp );
		$mockFileObjectForSecondQuery = $this->createMock( File::class );
		$mockFileObjectForSecondQuery->method( 'getTimestamp' )
			->willReturn( $expectedLastFileTimestamp );
		$mockMediaModerationFileFactory->method( 'getFileObjectForRow' )
			->withConsecutive(
				[ $lastResultRow, 'image' ],
				[ $lastResultRowForSecondQuery, 'image' ]
			)
			->willReturn( $mockFileObject, $mockFileObjectForSecondQuery );
		// Call the method under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mediaModerationFileFactory = $mockMediaModerationFileFactory;
		$this->assertArrayEquals(
			[ $secondQueryResult, $expectedLastFileTimestamp, $secondQueryResult->count() ],
			$objectUnderTest->getRowsForBatch( 'image', $previousBatchFinalTimestamp ),
			true,
			false,
			'::getRowsForBatch did not return the expected array when no rows are returned by the ' .
			'FileSelectQueryBuilder::fetchResultSet call.'
		);
	}

	public static function provideGetRowsForBatchWhenRowsAllHaveTheSameTimestamp() {
		// The fake result rows are special as they contain information about
		// the File object that would be created by the mock implementation of
		// MediaModerationFileFactory::getFileObjectForRow.
		return [
			'One row returned by the query with the same previous batch timestamp' => [
				'20230506070809',
				new FakeResultWrapper( [ [ 'timestamp' => '20230506070809' ] ] ),
				new FakeResultWrapper( [
					[ 'timestamp' => '20230506070809' ],
					[ 'timestamp' => '20230506070810' ]
				] ),
				'20230506070810',
			],
			'Multiple rows returned by the query' => [
				'',
				new FakeResultWrapper( [
					[ 'timestamp' => '20230405060701' ],
					[ 'timestamp' => '20230405060701' ]
				] ),
				new FakeResultWrapper( [
					[ 'timestamp' => '20230405060701' ],
					[ 'timestamp' => '20230405060701' ],
					[ 'timestamp' => '20230405060701' ],
					[ 'timestamp' => '20230405060702' ]
				] ),
				'20230405060702',
			],
		];
	}

	/** @dataProvider provideGetEstimatedNumberOfBatchesForTable */
	public function testGetEstimatedNumberOfBatchesForTable(
		$rowCount, $batchSize, $startTimestamp, $expectedReturnValue
	) {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ImportExistingFilesToScanTable::class )
			->onlyMethods( [ 'getBatchSize' ] )
			->getMock();
		$mockDbr = $this->createMock( IReadableDatabase::class );
		// Create a mock SelectQueryBuilder that expects that ::fetchField is called.
		$mockSelectQueryBuilder = $this->getMockBuilder( SelectQueryBuilder::class )
			->setConstructorArgs( [ $mockDbr ] )
			->onlyMethods( [ 'fetchField' ] )
			->getMock();
		$mockSelectQueryBuilder->expects( $this->once() )
			->method( 'fetchField' )
			->willReturn( $rowCount );
		// Create a mock IReadableDatabase that returns the mock SelectQueryBuilder,
		// and returns a mock Expression on call to ::expr
		$mockDbr->expects( $this->once() )
			->method( 'newSelectQueryBuilder' )
			->willReturn( $mockSelectQueryBuilder );
		$mockExpression = $this->createMock( Expression::class );
		$mockDbr->method( 'expr' )
			->with( 'img_timestamp', '>=', $startTimestamp )
			->willReturn( $mockExpression );
		$mockDbr->method( 'timestamp' )
			->with( $startTimestamp )
			->willReturn( $startTimestamp );
		// Mock the batch size
		$objectUnderTest->method( 'getBatchSize' )
			->willReturn( $batchSize );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->dbr = $mockDbr;
		$objectUnderTest->mediaModerationFileLookup = $this->newServiceInstance(
			MediaModerationFileLookup::class, []
		);
		// Call the method under test
		$this->assertSame(
			$expectedReturnValue,
			$objectUnderTest->getEstimatedNumberOfBatchesForTable( 'image', $startTimestamp ),
			'::getEstimatedNumberOfBatchesForTable did not return the expected number.'
		);
		// Generate the expected 'conds' array.
		$expectedConds = [];
		if ( $startTimestamp ) {
			$expectedConds[] = $mockExpression;
		}
		// Expect that the query was made correctly by looking at the result of ::getQueryInfo
		// for the partially mocked SelectQueryBuilder.
		$this->assertArrayEquals(
			[
				'tables' => [ 'image' ],
				'fields' => [ 'COUNT(*)' ],
				'caller' => 'MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable' .
					'::getEstimatedNumberOfBatchesForTable',
				'options' => [],
				'conds' => $expectedConds,
				'join_conds' => [],
			],
			$mockSelectQueryBuilder->getQueryInfo(),
			false,
			true,
			'::getQueryInfo did not return the expected array, suggesting that the query performed ' .
			'was incorrect.'
		);
	}

	public static function provideGetEstimatedNumberOfBatchesForTable() {
		return [
			'Row count as 0' => [ 0, null, '', 1 ],
			'Row count as 1, batch size 3' => [ 1, 3, '', 1 ],
			'Row count as 10, batch size 5' => [ 10, 5, '', 3 ],
			'Row count as 9, batch size 5' => [ 9, 5, '', 2 ],
			'Row count as 14, batch size 3, start timestamp defined' => [ 14, 3, '20230405060708', 5 ],
			'Row count as 100000, batch size 200' => [ 100000, 200, '', 501 ],
		];
	}

	/** @dataProvider provideGetTablesToProcess */
	public function testGetTablesToProcess( $tableOptionValue, $expectedReturnValue ) {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ImportExistingFilesToScanTable::class )
			->onlyMethods( [ 'error', 'getOption' ] )
			->getMock();
		if ( $expectedReturnValue === false ) {
			// If the $expectedReturnValue is false, then a call to ::error should be made.
			$objectUnderTest->expects( $this->once() )
				->method( 'error' );
		} else {
			// If the $expectedReturnValue is not false, then a call to ::error should never be made.
			$objectUnderTest->expects( $this->never() )
				->method( 'error' );
		}
		// Expect that the ::getOption method is called, and mock the return value as $tableOptionValue
		$objectUnderTest->expects( $this->once() )
			->method( 'getOption' )
			->with( 'table', ImportExistingFilesToScanTable::TABLES_TO_IMPORT_FROM )
			->willReturn( $tableOptionValue );
		// Call the method under test and expect that the return value is $expectedReturnValue
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		if ( is_array( $expectedReturnValue ) ) {
			$this->assertArrayEquals(
				$expectedReturnValue,
				$objectUnderTest->getTablesToProcess(),
				true,
				true,
				'::getTablesToProcess did not return the expected value.'
			);
		} else {
			$this->assertSame(
				$expectedReturnValue,
				$objectUnderTest->getTablesToProcess(),
				'::getTablesToProcess did not return the expected value.'
			);
		}
	}

	public static function provideGetTablesToProcess() {
		return [
			'getOption returns an empty array' => [ [], false ],
			'getOption returns an array with invalid tables' => [
				[ 'image', 'invalidtable', 'oldimage' ],
				false,
			],
			'getOption uses the default' => [
				ImportExistingFilesToScanTable::TABLES_TO_IMPORT_FROM,
				ImportExistingFilesToScanTable::TABLES_TO_IMPORT_FROM,
			],
		];
	}

	/** @dataProvider provideGenerateDBUpdatesReturnValue */
	public function testGenerateDBUpdatesReturnValue(
		$markCompleteSpecified, $startTimestamp, $table, $expectedReturnValue
	) {
		// Get the object under test
		$objectUnderTest = new ImportExistingFilesToScanTable();
		if ( $markCompleteSpecified ) {
			$objectUnderTest->setOption( 'mark-complete', 1 );
		}
		$objectUnderTest->setOption( 'start-timestamp', $startTimestamp );
		if ( $table !== null ) {
			$objectUnderTest->setOption( 'table', $table );
		}
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			$expectedReturnValue,
			$objectUnderTest->generateDBUpdatesReturnValue(),
			'::generateDBUpdatesReturnValue did not return the expected value.'
		);
	}

	public static function provideGenerateDBUpdatesReturnValue() {
		return [
			'mark-complete specified' => [ true, '', null, true ],
			'mark-complete specified with other options not as defaults' => [ true, '2023', [], true ],
			'Start timestamp defined' => [ false, '2023', null, false ],
			'Tables array does not include all tables' => [ false, '', [ 'image' ], false ],
			'Tables array and start timestamp are their defaults' => [ false, '', null, true ],
		];
	}
}
