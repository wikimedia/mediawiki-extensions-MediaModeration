<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Services;

use ArchivedFile;
use File;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\Expression;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampException;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup
 * @group MediaModeration
 */
class MediaModerationDatabaseLookupTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	/** @dataProvider provideFileExistsInScanTable */
	public function testFileExistsInScanTable( $flags, $expectedProviderMethodName, $fileObjectClass ) {
		$mockDb = $this->createMock( IDatabase::class );
		$selectQueryBuilderMock = $this->getMockBuilder( SelectQueryBuilder::class )
			->setConstructorArgs( [ $mockDb ] )
			->onlyMethods( [ 'fetchField' ] )
			->getMock();
		// Expect that fetchField is called. The expected fields, table etc. will
		// be checked after the call to the method.
		$selectQueryBuilderMock->expects( $this->once() )
			->method( 'fetchField' )
			->willReturn( true );
		$mockDb->expects( $this->once() )->method( 'newSelectQueryBuilder' )
			->willReturn( $selectQueryBuilderMock );
		$connectionProviderMock = $this->createMock( IConnectionProvider::class );
		// Expect that the getPrimaryDatabase or getReplicaDatabase method
		// is called as expected by $expectedProviderMethodName
		$connectionProviderMock->expects( $this->once() )
			->method( $expectedProviderMethodName )
			->willReturn( $mockDb );
		$objectUnderTest = new MediaModerationDatabaseLookup( $connectionProviderMock );
		// Create a mock File object
		/** @var File|ArchivedFile $mockFile */
		$mockFile = $this->createMock( $fileObjectClass );
		$mockFile->expects( $this->once() )
			->method( 'getSha1' )
			->willReturn( '123456abcdef' );
		// Call the method under test
		$this->assertTrue( $objectUnderTest->fileExistsInScanTable( $mockFile, $flags ) );
		$actualQueryInfo = $selectQueryBuilderMock->getQueryInfo();
		// Remove the 'caller' key from the actual query info
		unset( $actualQueryInfo['caller'] );
		// Expect that the ::getQueryInfo of the SelectQueryBuilder returns
		// the expected data, which also tests that the correct builder methods
		// are called.
		$this->assertArrayEquals(
			[
				'tables' => [ 'mediamoderation_scan' ],
				'fields' => [ 'COUNT(*)' ],
				'conds' => [ 'mms_sha1' => '123456abcdef' ],
				'join_conds' => [],
				'options' => [],
			],
			$actualQueryInfo,
			true,
			true,
			'The result from ::getQueryInfo was not as expected, suggesting the query that was performed ' .
			'was not as expected.'
		);
	}

	public static function provideFileExistsInScanTable() {
		return [
			'Reads from replica with flags as READ_NORMAL' => [
				IDBAccessObject::READ_NORMAL, 'getReplicaDatabase', File::class
			],
			'Reads from primary with flags as READ_LATEST' => [
				IDBAccessObject::READ_LATEST, 'getPrimaryDatabase', File::class
			],
			'Accepts an ArchivedFile object when reading from replica' => [
				IDBAccessObject::READ_NORMAL, 'getReplicaDatabase', ArchivedFile::class
			],
			'Accepts an ArchivedFile object when reading from primary' => [
				IDBAccessObject::READ_LATEST, 'getPrimaryDatabase', ArchivedFile::class
			],
		];
	}

	/** @dataProvider provideGetMatchStatusForSha1 */
	public function testGetMatchStatusForSha1(
		$flags, $expectedProviderMethodName, $mockedQueryResult, $expectedReturnValue
	) {
		$sha1 = 'abc1234';
		$mockDb = $this->createMock( IDatabase::class );
		$selectQueryBuilderMock = $this->getMockBuilder( SelectQueryBuilder::class )
			->setConstructorArgs( [ $mockDb ] )
			->onlyMethods( [ 'fetchField' ] )
			->getMock();
		// Expect that fetchField is called and make it return $mockedQueryResult
		$selectQueryBuilderMock->expects( $this->once() )
			->method( 'fetchField' )
			->willReturn( $mockedQueryResult );
		$mockDb->expects( $this->once() )->method( 'newSelectQueryBuilder' )
			->willReturn( $selectQueryBuilderMock );
		$connectionProviderMock = $this->createMock( IConnectionProvider::class );
		// Expect that the getPrimaryDatabase or getReplicaDatabase method
		// is called as expected by $expectedProviderMethodName
		$connectionProviderMock->expects( $this->once() )
			->method( $expectedProviderMethodName )
			->willReturn( $mockDb );
		$objectUnderTest = new MediaModerationDatabaseLookup( $connectionProviderMock );
		// Call the method under test and verify the method returns the expected value.
		$this->assertSame(
			$expectedReturnValue,
			$objectUnderTest->getMatchStatusForSha1( $sha1, $flags ),
			'::getMatchStatusForSha1 did not return the expected value.'
		);
		$actualQueryInfo = $selectQueryBuilderMock->getQueryInfo();
		// Remove the 'caller' key from the actual query info
		unset( $actualQueryInfo['caller'] );
		// Expect that the ::getQueryInfo of the SelectQueryBuilder returns
		// the expected data, which also tests that the correct builder methods
		// are called.
		$this->assertArrayEquals(
			[
				'tables' => [ 'mediamoderation_scan' ],
				'fields' => [ 'mms_is_match' ],
				'conds' => [ 'mms_sha1' => $sha1 ],
				'join_conds' => [],
				'options' => [],
			],
			$actualQueryInfo,
			true,
			true,
			'The result from ::getQueryInfo was not as expected, suggesting the query that was performed ' .
			'was not as expected.'
		);
	}

	public static function provideGetMatchStatusForSha1() {
		return [
			'Match status from DB is false when reading from replica' => [
				IDBAccessObject::READ_NORMAL, 'getReplicaDatabase', '0', false,
			],
			'Match status from DB is true when reading from primary' => [
				IDBAccessObject::READ_LATEST, 'getPrimaryDatabase', '1', true,
			],
			'Match status from DB is NULL when reading from replica' => [
				IDBAccessObject::READ_NORMAL, 'getReplicaDatabase', null, null,
			],
		];
	}

	/** @dataProvider provideValidTimestamps */
	public function testGetDateFromTimestamp( $timestamp, $expectedReturnValue ) {
		/** @var MediaModerationDatabaseLookup $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( MediaModerationDatabaseLookup::class, [] );
		$this->assertSame(
			$expectedReturnValue,
			$objectUnderTest->getDateFromTimestamp( $timestamp ),
			'::getDateFromTimestamp did not return the expected date in the format YYYYMMDD.'
		);
	}

	public static function provideValidTimestamps() {
		return [
			'ConvertibleTimestamp instance created using an integer' => [
				new ConvertibleTimestamp( 1234 ),
				'19700101',
			],
			'ConvertibleTimestamp instance created using a string' => [
				new ConvertibleTimestamp( '20230504030201' ),
				'20230504',
			],
			'String in TS_MW format' => [ '20230405020301', '20230405' ],
			'Integer (TS_UNIX)' => [ 123456, '19700102' ],
			'Integer (TS_UNIX) from 2023' => [ 1701725115, '20231204' ],
		];
	}

	/** @dataProvider provideInvalidTimestamps */
	public function testGetDateFromTimestampOnInvalidTimestamp( $timestamp ) {
		// A TimestampException will be thrown by ConvertibleTimestamp on an invalid timestamp
		$this->expectException( TimestampException::class );
		// Get the object under test
		/** @var MediaModerationDatabaseLookup $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( MediaModerationDatabaseLookup::class, [] );
		$objectUnderTest->getDateFromTimestamp( $timestamp );
	}

	public static function provideInvalidTimestamps() {
		return [
			'String in invalid format' => [ 'abc10245' ],
		];
	}

	public function testGetSha1ValuesForScan() {
		// Create a mock SelectQueryBuilder that expects that
		// the mms_sha1 field is added, a LIMIT is added and then
		// the ::fetchFieldValues method is called.
		$mockDbr = $this->createMock( IReadableDatabase::class );
		$selectQueryBuilderMock = $this->getMockBuilder( SelectQueryBuilder::class )
			->setConstructorArgs( [ $mockDbr ] )
			->onlyMethods( [ 'fetchFieldValues' ] )
			->getMock();
		// Make ::fetchFieldValues return some fake SHA-1 values.
		$selectQueryBuilderMock->expects( $this->once() )
			->method( 'fetchFieldValues' )
			->willReturn( [ 'test', 'testing' ] );
		// Get the object under test, with ::newSelectQueryBuilder mocked
		$objectUnderTest = $this->getMockBuilder( MediaModerationDatabaseLookup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'newSelectQueryBuilderForScan' ] )
			->getMock();
		$objectUnderTest->expects( $this->once() )
			->method( 'newSelectQueryBuilderForScan' )
			->willReturn( $selectQueryBuilderMock );
		// Call the method under test
		$this->assertArrayEquals(
			[ 'test', 'testing' ],
			$objectUnderTest->getSha1ValuesForScan( 123, '', SelectQueryBuilder::SORT_ASC, [], '' ),
			true,
			true,
			'::getSha1ValuesForScan did not return the expected results array.'
		);
		$this->assertArrayEquals(
			[ 'mms_sha1' ],
			$selectQueryBuilderMock->getQueryInfo()['fields'],
			true,
			false,
			'The field being used in the select query was not the SHA-1 field.'
		);
		$this->assertSame(
			123,
			$selectQueryBuilderMock->getQueryInfo()['options']['LIMIT'],
			'The wrong LIMIT was used to get the SHA-1 values.'
		);
	}

	/** @dataProvider provideNewSelectQueryBuilderForScan */
	public function testNewSelectQueryBuilderForScan(
		$lastChecked, $direction, $excludedSha1Values, $matchStatus, $dbType,
		$expectedConds, $expectedOptions, $mockDbr = null
	) {
		// Create a mock IConnectionProvider that returns a mock IReadableDatabase
		$mockConnectionProvider = $this->createMock( IConnectionProvider::class );
		$mockDbr = $mockDbr ?? $this->createMock( IReadableDatabase::class );
		$mockConnectionProvider->method( 'getReplicaDatabase' )
			->willReturn( $mockDbr );
		// Mock that the $mockDbr returns $dbType as the result of IReadableDatabase::getType
		$mockDbr->method( 'getType' )
			->willReturn( $dbType );
		// Make $mockDbr::newSelectQueryBuilder perform exactly the same thing
		// as in a real database, but instead using the $mockDbr.
		$mockDbr->method( 'newSelectQueryBuilder' )
			->willReturn( new SelectQueryBuilder( $mockDbr ) );
		// Get the object under test and call the method under test
		$objectUnderTest = $this->newServiceInstance( MediaModerationDatabaseLookup::class, [
			'connectionProvider' => $mockConnectionProvider
		] );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$returnedSelectQueryBuilder = $objectUnderTest->newSelectQueryBuilderForScan(
			$lastChecked, $direction, $excludedSha1Values, $matchStatus
		);
		// Check that the WHERE conditions and options array returned by ::getQueryInfo
		// method of the result of ::newSelectQueryBuilder is as expected.
		$this->assertCount(
			0,
			$returnedSelectQueryBuilder->getQueryInfo()['fields'],
			'No fields should have been added to the query builder returned by ::newSelectQueryBuilder'
		);
		$this->assertArrayEquals(
			[ 'mediamoderation_scan' ],
			$returnedSelectQueryBuilder->getQueryInfo()['tables'],
			true,
			true,
			'Only the mediamoderation_scan table should have been added to the query builder returned by ' .
			'::newSelectQueryBuilder.'
		);
		$this->assertArrayEquals(
			$expectedConds,
			$returnedSelectQueryBuilder->getQueryInfo()['conds'],
			true,
			true,
			'::getQueryInfo for the SelectQueryBuilder returned by ::newSelectQueryBuilder did not return ' .
			'the expected WHERE conditions, suggesting the query builder was constructed wrong.'
		);
		$this->assertArrayEquals(
			$expectedOptions,
			$returnedSelectQueryBuilder->getQueryInfo()['options'],
			'::getQueryInfo for the SelectQueryBuilder returned by ::newSelectQueryBuilder did not return ' .
			'the expected value, suggesting that the query builder was constructed wrong.'
		);
	}

	public static function provideNewSelectQueryBuilderForScan() {
		return [
			'Last checked null, direction ASC, any match status, on mariadb' => [
				// $lastChecked parameter
				null,
				// $direction parameter
				SelectQueryBuilder::SORT_ASC,
				// $excludedSha1Values parameter
				[],
				// $matchStatus parameter
				MediaModerationDatabaseLookup::ANY_MATCH_STATUS,
				// String to be returned by IReadableDatabase::getType
				'mariadb',
				// The expected WHERE conditions
				[ 'mms_last_checked' => null ],
				// The expected 'options' array in the SelectQueryBuilder
				[ 'ORDER BY' => [ 'mms_last_checked ASC' ] ],
			],
			'Last checked as null, direction DESC, match status null, on mariadb' => [
				null,
				SelectQueryBuilder::SORT_DESC,
				[],
				null,
				'mariadb',
				[ 'mms_last_checked' => null, 'mms_is_match' => null ],
				[ 'ORDER BY' => [ 'mms_last_checked DESC' ] ],
			],
			'Last checked as null, direction DESC, match status null, on postgres' => [
				null,
				SelectQueryBuilder::SORT_DESC,
				[],
				'1',
				'postgres',
				[ 'mms_last_checked' => null, 'mms_is_match' => '1' ],
				[ 'ORDER BY' => [ 'mms_last_checked DESC NULLS LAST' ] ],
			],
			'Last checked as null, direction ASC, match status as 0, on postgres' => [
				null,
				SelectQueryBuilder::SORT_ASC,
				[],
				'0',
				'postgres',
				[ 'mms_last_checked' => null, 'mms_is_match' => '0' ],
				[ 'ORDER BY' => [ 'mms_last_checked ASC NULLS FIRST' ] ],
			],
		];
	}

	public function testNewSelectQueryBuilderForScanForExcludedSha1() {
		$mockExpression = $this->createMock( Expression::class );
		$mockDbr = $this->createMock( IReadableDatabase::class );
		$mockDbr->method( 'expr' )
			->with( 'mms_sha1', '!=', [ 'test1234', 'testingabc' ] )
			->willReturn( $mockExpression );
		$this->testNewSelectQueryBuilderForScan(
			null,
			SelectQueryBuilder::SORT_DESC,
			[ 'test1234', 'testingabc' ],
			null,
			'mariadb',
			[ 'mms_last_checked' => null, 'mms_is_match' => null, $mockExpression ],
			[ 'ORDER BY' => [ 'mms_last_checked DESC' ] ],
			$mockDbr
		);
	}
}
