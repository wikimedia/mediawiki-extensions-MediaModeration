<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Services;

use InvalidArgumentException;
use LocalFile;
use LocalRepo;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileFactory;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup
 * @group MediaModeration
 */
class MediaModerationFileLookupTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	/** @dataProvider provideMethodsThatAcceptOnlyATableName */
	public function testMethodsThrowInvalidArgumentExceptionOnInvalidTable( $method ) {
		// Tests that a unrecognised $table throws an InvalidArgumentException.
		$this->expectException( InvalidArgumentException::class );
		/** @var MediaModerationFileLookup $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( MediaModerationFileLookup::class, [] );
		// Needed as ::getSha1FieldForTable is a private method.
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->$method( 'testing-table' );
	}

	public static function provideMethodsThatAcceptOnlyATableName() {
		return [
			'::getFileSelectQueryBuilder' => [ 'getFileSelectQueryBuilder' ],
			'::getTimestampFieldForTable' => [ 'getTimestampFieldForTable' ],
			'::getSha1FieldForTable' => [ 'getSha1FieldForTable' ],
		];
	}

	/** @dataProvider provideGetTimestampField */
	public function testGetTimestampField( $table, $expectedTimestampField ) {
		// Get the object under test.
		/** @var MediaModerationFileLookup $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( MediaModerationFileLookup::class, [] );
		// Call the method under test
		$this->assertSame(
			$expectedTimestampField,
			$objectUnderTest->getTimestampFieldForTable( $table ),
			'::getTimestampFieldForTable did not return the expected timestamp field.'
		);
	}

	public static function provideGetTimestampField() {
		return [
			'image table' => [ 'image', 'img_timestamp' ],
			'oldimage table' => [ 'oldimage', 'oi_timestamp' ],
			'filearchive table' => [ 'filearchive', 'fa_timestamp' ],
		];
	}

	/** @dataProvider provideGetBatchOfFileRows */
	public function testGetBatchOfFileRows(
		$table, $startTimestamp, $batchSize, $getRowCountForTimestampResult,
		$getBatchOfFileRowsResult, $expectedBatchSizeForQuery, $expectedReturnedRows,
		$expectedReturnedStartTimestamp
	) {
		// Define a mock $sha1.
		$sha1 = '1234test';
		// Get the object under test, mocking ::performBatchQuery and ::getRowCountForTimestamp
		$objectUnderTest = $this->getMockBuilder( MediaModerationFileLookup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'performBatchQuery', 'getRowCountForTimestamp' ] )
			->getMock();
		// Define the mock of ::getRowCountForTimestamp to return $getRowCountForTimestampResult
		$objectUnderTest->expects( $this->once() )
			->method( 'getRowCountForTimestamp' )
			->with( $table, $startTimestamp, $sha1 )
			->willReturn( $getRowCountForTimestampResult );
		// Define the mock of ::getBatchOfFileRows to return $getBatchOfFileRowsResult
		$objectUnderTest->expects( $this->once() )
			->method( 'performBatchQuery' )
			->with( $table, $startTimestamp, $sha1, $expectedBatchSizeForQuery )
			->willReturn( $getBatchOfFileRowsResult );
		// Call the method under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$actualReturnedArray = $objectUnderTest->getBatchOfFileRows( $table, $startTimestamp, $sha1, $batchSize );
		$actualReturnedRows = array_map(
			static function ( $element ) {
				return (array)$element;
			},
			iterator_to_array( $actualReturnedArray[0] )
		);
		$this->assertSame(
			$expectedReturnedRows,
			$actualReturnedRows,
			'::getBatchOfFileRows did not return the expected results.'
		);
		$this->assertSame(
			$expectedReturnedStartTimestamp,
			$actualReturnedArray[1],
			'::getBatchOfFileRows did not return the expected timestamp as the second item of the array'
		);
	}

	public static function provideGetBatchOfFileRows() {
		return [
			'image table, no start timestamp, batch size 2, row count 2' => [
				// The table parameter
				'image',
				// The start timestamp parameter
				'',
				// The batch size parameter
				2,
				// The result of ::getRowCountForTimestamp
				2,
				// The result of ::performBatchQuery
				new FakeResultWrapper( [
					[ 'img_timestamp' => '20230405060708' ],
					[ 'img_timestamp' => '20230405060707' ],
					[ 'img_timestamp' => '20230405060700' ],
				] ),
				// The batch size that should have been passed to ::performBatchQuery
				3,
				// The rows that are expected to be returned by the method under test
				[
					[ 'img_timestamp' => '20230405060708' ],
					[ 'img_timestamp' => '20230405060707' ],
				],
				// The timestamp expected to be returned by the method under test
				'20230405060700',
			],
			'oldimage, start timestamp, batch size 2, row count 3' => [
				'oldimage',
				'20230405060709',
				2,
				3,
				new FakeResultWrapper( [
					[ 'oi_timestamp' => '20230405060708' ],
					[ 'oi_timestamp' => '20230405060706' ],
					[ 'oi_timestamp' => '20230405060700' ],
					[ 'oi_timestamp' => '20230405060700' ],
				] ),
				4,
				[
					[ 'oi_timestamp' => '20230405060708' ],
					[ 'oi_timestamp' => '20230405060706' ],
				],
				'20230405060700',
			],
			'oldimage, start timestamp, batch size 3, row count 2' => [
				'oldimage',
				'20230405060708',
				3,
				2,
				new FakeResultWrapper( [
					[ 'oi_timestamp' => '20230405060708' ],
					[ 'oi_timestamp' => '20230405060706' ],
				] ),
				4,
				[
					[ 'oi_timestamp' => '20230405060708' ],
					[ 'oi_timestamp' => '20230405060706' ],
				],
				false,
			],
			'filearchive, start timestamp equal to timestamp of all rows but last, batch size 3, row count 3' => [
				'filearchive',
				'20230405060708',
				3,
				3,
				new FakeResultWrapper( [
					[ 'fa_timestamp' => '20230405060708' ],
					[ 'fa_timestamp' => '20230405060708' ],
					[ 'fa_timestamp' => '20230405060708' ],
					[ 'fa_timestamp' => '20230405060707' ],
				] ),
				4,
				[
					[ 'fa_timestamp' => '20230405060708' ],
					[ 'fa_timestamp' => '20230405060708' ],
					[ 'fa_timestamp' => '20230405060708' ],
				],
				'20230405060707',
			],
			'filearchive, start timestamp equal to timestamp of all rows, batch size 3, row count 3' => [
				'filearchive',
				'20230405060708',
				3,
				3,
				new FakeResultWrapper( [
					[ 'fa_timestamp' => '20230405060708' ],
					[ 'fa_timestamp' => '20230405060708' ],
					[ 'fa_timestamp' => '20230405060708' ],
				] ),
				4,
				[
					[ 'fa_timestamp' => '20230405060708' ],
					[ 'fa_timestamp' => '20230405060708' ],
					[ 'fa_timestamp' => '20230405060708' ],
				],
				false,
			],
		];
	}

	/** @dataProvider provideGetFileObjectsForSha1 */
	public function testGetFileObjectsForSha1( $batches, $expectedFileFactoryCallCount ) {
		// Create a mock MediaModerationFileFactory that expects that it is called $expectedFileFactoryCallCount times
		$mockMediaModerationFileFactory = $this->createMock( MediaModerationFileFactory::class );
		$mockMediaModerationFileFactory->expects( $this->exactly( $expectedFileFactoryCallCount ) )
			->method( 'getFileObjectForRow' )
			->willReturn( $this->createMock( LocalFile::class ) );
		// Get the object under test, mocking ::getBatchOfFileRows
		$objectUnderTest = $this->getMockBuilder( MediaModerationFileLookup::class )
			->setConstructorArgs( [
				'localRepo' => $this->createMock( LocalRepo::class ),
				'mediaModerationFileFactory' => $mockMediaModerationFileFactory,
			] )
			->onlyMethods( [ 'getBatchOfFileRows' ] )
			->getMock();
		// Mock ::getBatchOfFileRows to return the the results from $batches
		$objectUnderTest->method( 'getBatchOfFileRows' )
			->willReturnOnConsecutiveCalls( ...$batches );
		// Call the method under test (::getFileObjectsForSha1)
		$this->assertCount(
			$expectedFileFactoryCallCount,
			iterator_to_array( $objectUnderTest->getFileObjectsForSha1( '1234' ) ),
			'::getFileObjectsForSha1 did not return the expected number of LocalFile objects.'
		);
	}

	public static function provideGetFileObjectsForSha1() {
		return [
			'No rows in the batches' => [
				// $batches, which is an array of return values by ::getBatchOfFileRows
				[
					// Batches for 'image'
					[ new FakeResultWrapper( [] ), false ],
					// Batches for 'oldimage'
					[ new FakeResultWrapper( [] ), false ],
					// Batches for 'filearchive'
					[ new FakeResultWrapper( [] ), false ],
				],
				// $expectedFileFactoryCallCount
				0,
			],
			'One table with one row' => [
				// $batches, which is an array of return values by ::getBatchOfFileRows
				[
					// Batches for 'image'
					[ new FakeResultWrapper( [ [ 'test' => 'testing' ] ] ), false ],
					// Batches for 'oldimage'
					[ new FakeResultWrapper( [] ), false ],
					// Batches for 'filearchive'
					[ new FakeResultWrapper( [] ), false ],
				],
				// $expectedFileFactoryCallCount
				1,
			],
			'All tables have one row' => [
				// $batches, which is an array of return values by ::getBatchOfFileRows
				[
					// Batches for 'image'
					[ new FakeResultWrapper( [ [ 'test' => 'testing' ] ] ), false ],
					// Batches for 'oldimage'
					[ new FakeResultWrapper( [ [ 'test' => 'testing' ] ] ), false ],
					// Batches for 'filearchive'
					[ new FakeResultWrapper( [ [ 'test' => 'testing' ] ] ), false ],
				],
				// $expectedFileFactoryCallCount
				3,
			],
			'All tables have one more than one batch' => [
				// $batches, which is an array of return values by ::getBatchOfFileRows
				[
					// Batches for 'image'
					[ new FakeResultWrapper( [ [ 'test' => 'testing0' ] ] ), '1234' ],
					[ new FakeResultWrapper( [ [ 'test' => 'testing10' ] ] ), false ],
					// Batches for 'oldimage'
					[ new FakeResultWrapper( [ [ 'test' => 'testing1' ] ] ), '1234' ],
					[ new FakeResultWrapper( [ [ 'test' => 'testing2' ] ] ), '123456' ],
					[ new FakeResultWrapper( [ [ 'test' => 'testing3' ] ] ), false ],
					// Batches for 'filearchive'
					[ new FakeResultWrapper( [ [ 'test' => 'testing4' ] ] ), '1234' ],
					[ new FakeResultWrapper( [ [ 'test' => 'testing5' ] ] ), false ],
				],
				// $expectedFileFactoryCallCount
				7,
			],
		];
	}
}
