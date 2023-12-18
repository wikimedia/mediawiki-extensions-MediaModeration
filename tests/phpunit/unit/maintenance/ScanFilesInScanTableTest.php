<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Maintenance;

use Generator;
use MediaWiki\Extension\MediaModeration\Maintenance\ScanFilesInScanTable;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Language\RawMessage;
use MediaWiki\Status\StatusFormatter;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use ReflectionClass;
use StatusValue;
use Wikimedia\Rdbms\LBFactory;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\ScanFilesInScanTable
 * @group MediaModeration
 */
class ScanFilesInScanTableTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	/** @dataProvider provideGenerateSha1ValuesForScan */
	public function testGenerateSha1ValuesForScan(
		$batchSize, $returnedBatchesOfSha1Values, $expectedSha1Values
	) {
		// Define a fake 'last-checked' value for the test.
		$lastChecked = '20230405';
		// Create a mock MediaModerationDatabaseLookup to return pre-defined arrays of SHA-1
		// values from ::getSha1ValuesForScan
		$mockMediaModerationDatabaseLookup = $this->createMock( MediaModerationDatabaseLookup::class );
		$mockMediaModerationDatabaseLookup->expects( $this->exactly( count( $returnedBatchesOfSha1Values ) ) )
			->method( 'getSha1ValuesForScan' )
			->with(
				$batchSize,
				$lastChecked,
				SelectQueryBuilder::SORT_ASC,
				MediaModerationDatabaseLookup::NULL_MATCH_STATUS
			)
			->willReturnOnConsecutiveCalls( ...$returnedBatchesOfSha1Values );
		// Get the object under test, with the MediaModerationDatabaseLookup and LoadBalancerFactory services mocked.
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'initServices' ] )
			->getMock();
		// Set 'sleep' option as 0 to prevent unit tests from running slowly.
		$objectUnderTest->setOption( 'sleep', 0 );
		// Actually assign the mock services for the test.
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mediaModerationDatabaseLookup = $mockMediaModerationDatabaseLookup;
		$objectUnderTest->loadBalancerFactory = $this->createMock( LBFactory::class );
		$objectUnderTest->setBatchSize( $batchSize );
		$objectUnderTest->lastChecked = $lastChecked;
		// Call the method under test.
		$actualGenerator = $objectUnderTest->generateSha1ValuesForScan();
		// Assert that the object is a generator
		$this->assertInstanceOf( Generator::class, $actualGenerator );
		// Convert the Generator to an array and expect that the items are as expected by $expectedSha1Values
		$this->assertArrayEquals(
			$expectedSha1Values,
			iterator_to_array( $actualGenerator, false ),
			true,
			false,
			'::generateSha1ValuesForScan did not return the expected SHA-1 values.'
		);
	}

	public static function provideGenerateSha1ValuesForScan() {
		return [
			'One batch of SHA-1 values with batch size as 3' => [
				// Batch size used by the maintenance script (null indicates the default of 200).
				3,
				// Array of batches of SHA-1 values that are the mock return values of
				// ::getSha1ValuesForScan
				[
					[ 'abc123', 'def456', 'cab321' ],
					[],
				],
				// The expected SHA-1 values returned in the Generator from the method under test.
				[ 'abc123', 'def456', 'cab321' ],
			],
			'Two batches of SHA-1 values with batch size as 4' => [
				4,
				[
					[ 'abc123', 'def456', 'cab321', 'abcdef' ],
					[ 'abc1234', 'def4565', 'cab3212' ],
					[],
				],
				[ 'abc123', 'def456', 'cab321', 'abcdef', 'abc1234', 'def4565', 'cab3212' ],
			],
			'No batches of SHA-1 values with batch size as 4' => [
				4,
				[ [] ],
				[],
			],
		];
	}

	/** @dataProvider provideParseLastCheckedTimestamp */
	public function testParseLastCheckedTimestamp( $lastCheckedOptionValue, $expectedLastChecked ) {
		// Fix the current time as the method under test calls ConvertibleTimestamp::time.
		ConvertibleTimestamp::setFakeTime( '20230504030201' );
		// The MediaModerationDatabaseLookup service is used only non-DB related methods when calling
		// ::parseLastCheckedTimestamp (the method under test).
		$mediaModerationDatabaseLookup = $this->newServiceInstance( MediaModerationDatabaseLookup::class, [] );
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'initServices', 'fatalError' ] )
			->getMock();
		$objectUnderTest->expects( $this->never() )
			->method( 'fatalError' );
		// Assign the $lastCheckedOptionValue to the be the 'last-checked' option value.
		$objectUnderTest->setOption( 'last-checked', $lastCheckedOptionValue );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mediaModerationDatabaseLookup = $mediaModerationDatabaseLookup;
		// Call the method under test
		$objectUnderTest->parseLastCheckedTimestamp();
		// Assert that the $objectUnderTest->lastChecked value is now as expected.
		$this->assertSame(
			$expectedLastChecked,
			$objectUnderTest->lastChecked,
			"::parseLastCheckedTimestamp did not parse the 'last-checked' option in the expected way."
		);
	}

	public static function provideParseLastCheckedTimestamp() {
		// Fix the current time as the method under test calls ConvertibleTimestamp::time.
		ConvertibleTimestamp::setFakeTime( '20230504030201' );
		return [
			"Last checked as 'never'" => [ "never", null ],
			'Last checked not defined' => [ null, '20230503000000' ],
			'Last checked as YYYYMMDD' => [ '20220405', '20220405000000' ],
			'Last checked as a TS_MW timestamp' => [ '20230401020104', '20230401000000' ],
			'Last checked as TS_UNIX timestamp' => [ '2023-04-05 04:03:02', '20230405000000' ],
		];
	}

	/** @dataProvider provideParseLastCheckedTimestampOnInvalidLastChecked */
	public function testParseLastCheckedTimestampOnInvalidLastChecked( $lastCheckedOptionValue ) {
		// Fix the current time as the method under test calls ConvertibleTimestamp::time.
		ConvertibleTimestamp::setFakeTime( '20230504030201' );
		// The MediaModerationDatabaseLookup service is used only non-DB related methods when calling
		// ::parseLastCheckedTimestamp (the method under test).
		$mediaModerationDatabaseLookup = $this->newServiceInstance( MediaModerationDatabaseLookup::class, [] );
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'initServices', 'fatalError' ] )
			->getMock();
		$objectUnderTest->expects( $this->once() )
			->method( 'fatalError' )
			->with(
				'The --last-checked argument passed to this script could not be parsed. This can take a ' .
				'timestamp in string form, or a date in YYYYMMDD format.'
			);
		// Assign the $lastCheckedOptionValue to the be the 'last-checked' option value.
		$objectUnderTest->setOption( 'last-checked', $lastCheckedOptionValue );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mediaModerationDatabaseLookup = $mediaModerationDatabaseLookup;
		// Call the method under test
		$objectUnderTest->parseLastCheckedTimestamp();
	}

	public static function provideParseLastCheckedTimestampOnInvalidLastChecked() {
		return [
			'Unrecognised text' => [ 'testing' ],
			'Unrecognised integer' => [ '92034809235890348905839054324938590' ],
		];
	}

	public function testMaybeOutputVerboseInformationWhenNotInVerboseMode() {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'initServices', 'output', 'error' ] )
			->getMock();
		// Expect that ::output and ::error are never called
		$objectUnderTest->expects( $this->never() )
			->method( 'output' );
		$objectUnderTest->expects( $this->never() )
			->method( 'error' );
		// Call the method under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->maybeOutputVerboseInformation( 'abc1234', true );
	}

	/** @dataProvider provideMaybeOutputVerboseInformation */
	public function testMaybeOutputVerboseInformation(
		$sha1, $matchStatus, $expectedOutputMethod, $expectedOutputString
	) {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'initServices', 'output', 'error' ] )
			->getMock();
		$objectUnderTest->setOption( 'verbose', 1 );
		// Expect that ::output is called once
		$objectUnderTest->expects( $this->once() )
			->method( $expectedOutputMethod )
			->with( $expectedOutputString );
		// Call the method under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->maybeOutputVerboseInformation( $sha1, $matchStatus );
	}

	public static function provideMaybeOutputVerboseInformation() {
		return [
			'Null match status' => [
				'abc1234', null, 'error',
				"SHA-1 abc1234: Scan failed.\n"
			],
			'Positive match status' => [
				'abc123', true, 'output',
				"SHA-1 abc123: Positive match.\n"
			],
			'Negative match status' => [
				'abc12345', false, 'output',
				"SHA-1 abc12345: No match.\n"
			],
		];
	}

	public function testMaybeOutputVerboseStatusErrorWhenNotInVerboseMode() {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'initServices', 'error' ] )
			->getMock();
		// Expect that ::error is never called
		$objectUnderTest->expects( $this->never() )
			->method( 'error' );
		// Set up a variable named $hasOutputtedSha1 which has to be passed by reference to the method
		// under test
		$hasOutputtedSha1 = false;
		// Call the method under test
		// T287318 - TestingAccessWrapper::__call does not support pass-by-reference
		$classReflection = new ReflectionClass( $objectUnderTest );
		$methodReflection = $classReflection->getMethod( 'maybeOutputVerboseStatusError' );
		$methodReflection->setAccessible( true );
		$methodReflection->invokeArgs( $objectUnderTest, [
			StatusValue::newFatal( new RawMessage( "test" ) ),
			'abc123',
			&$hasOutputtedSha1
		] );
		$this->assertFalse(
			$hasOutputtedSha1,
			'::maybeOutputVerboseStatusError should not have modified $hasOutputtedSha1 if not in verbose mode.'
		);
	}

	/** @dataProvider provideMaybeOutputVerboseStatusError */
	public function testMaybeOutputVerboseStatusError(
		$checkResults, $sha1, $hasOutputtedSha1, $expectedOutputStrings, $expectedOutputtedSha1Value
	) {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'initServices', 'error' ] )
			->getMock();
		$objectUnderTest->setOption( 'verbose', 1 );
		// Expect that ::output is called once or twice depending on the value
		// in $hasOutputtedSha1, and that the strings are as expected
		$objectUnderTest->expects( $this->exactly( count( $expectedOutputStrings ) ) )
			->method( 'error' )
			->willReturnCallback( function ( $actualErrorString ) use ( $expectedOutputStrings ) {
				$this->assertContains(
					$actualErrorString,
					$expectedOutputStrings,
					'::maybeOutputVerboseStatusError did not output an expected string.'
				);
			} );
		// Define a mock StatusFormatter that returns the value in the RawMessage
		$mockStatusFormatter = $this->createMock( StatusFormatter::class );
		$mockStatusFormatter->method( 'getWikiText' )
			->willReturnCallback( static function ( StatusValue $status ) {
				/** @var RawMessage $rawError */
				$rawError = $status->getErrors()[0]['message'];
				return $rawError->fetchMessage();
			} );
		// T287318 - TestingAccessWrapper::__call does not support pass-by-reference
		$classReflection = new ReflectionClass( $objectUnderTest );
		$methodReflection = $classReflection->getMethod( 'maybeOutputVerboseStatusError' );
		$methodReflection->setAccessible( true );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->statusFormatter = $mockStatusFormatter;
		// Call the method under test for each $checkResult in the $checkResults array.
		foreach ( $checkResults as $checkResult ) {
			$methodReflection->invokeArgs( $objectUnderTest->object, [ $checkResult, $sha1, &$hasOutputtedSha1 ] );
		}
		$this->assertSame(
			$expectedOutputtedSha1Value,
			$hasOutputtedSha1,
			'::maybeOutputVerboseStatusError did not modify the $hasOutputtedSha1 argument in the expected way.'
		);
	}

	public static function provideMaybeOutputVerboseStatusError() {
		return [
			'One call to the method under test' => [
				// The StatusValue objects to pass to each call of the method under test
				// The method under test is called the number of times equal to the number
				// of items in this array.
				[
					StatusValue::newFatal( new RawMessage( "test" ) ),
				],
				// A SHA-1 value to be provided to the method under test
				'abc123',
				// The value of $hasOutputtedSha1 to be provided by reference to the method under test.
				false,
				// The expected strings passed to ::error by the method under test
				[
					"SHA-1 abc123\n",
					"...test\n",
				],
				// The expected value of $hasOutputtedSha1 at the end of the test
				true,
			],
			'Two calls to the method under test' => [
				// The StatusValue objects to pass to each call of the method under test
				// The method under test is called the number of times equal to the number
				// of items in this array.
				[
					StatusValue::newFatal( new RawMessage( "test" ) ),
					StatusValue::newFatal( new RawMessage( "test2" ) ),
				],
				// A SHA-1 value to be provided to the method under test
				'abc12345',
				// The value of $hasOutputtedSha1 to be provided by reference to the method under test.
				false,
				// The expected strings passed to ::error by the method under test
				[
					"SHA-1 abc12345\n",
					"...test\n",
					"...test2\n",
				],
				// The expected value of $hasOutputtedSha1 at the end of the test
				true,
			],
			'No calls to the method under test' => [
				[],
				'abc1234',
				false,
				[],
				false,
			]
		];
	}
}
