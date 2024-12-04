<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Maintenance;

use Generator;
use IJobSpecification;
use JobQueueError;
use JobQueueGroup;
use MediaWiki\Extension\MediaModeration\Maintenance\ScanFilesInScanTable;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileScanner;
use MediaWiki\Language\RawMessage;
use MediaWiki\Status\StatusFormatter;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use StatusValue;
use Wikimedia\Rdbms\Expression;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
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
		$batchSize, $usesJobQueue, $returnedBatchesOfSha1Values, $expectedSha1Values
	) {
		// Define a fake 'last-checked' value for the test.
		$lastChecked = '20230405';
		// Create a mock MediaModerationDatabaseLookup to return pre-defined arrays of SHA-1
		// values from ::getSha1ValuesForScan
		$mockMediaModerationDatabaseLookup = $this->createMock( MediaModerationDatabaseLookup::class );
		$mockMediaModerationDatabaseLookup->expects( $this->exactly( count( $returnedBatchesOfSha1Values ) ) )
			->method( 'getSha1ValuesForScan' )
			->with(
				$batchSize, $lastChecked, SelectQueryBuilder::SORT_ASC,
				[ 'sha-1-being-processed' ], MediaModerationDatabaseLookup::NULL_MATCH_STATUS,
			)
			->willReturnOnConsecutiveCalls( ...$returnedBatchesOfSha1Values );
		// Get the object under test, with the MediaModerationDatabaseLookup service mocked.
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'waitForJobQueueSize', 'waitForReplication' ] )
			->getMock();
		// Set 'sleep' option as 0 to prevent unit tests from running slowly.
		$objectUnderTest->setOption( 'sleep', 0 );
		// Expect that ::waitForJobQueueSize is called if $usesJobQueue is true
		$objectUnderTest->expects( $this->exactly( $usesJobQueue ? count( $returnedBatchesOfSha1Values ) : 0 ) )
			->method( 'waitForJobQueueSize' );
		if ( $usesJobQueue ) {
			$objectUnderTest->setOption( 'use-jobqueue', 1 );
		}
		$objectUnderTest->expects( $this->atLeastOnce() )
			->method( 'waitForReplication' );
		// Actually assign the mock services for the test.
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mediaModerationDatabaseLookup = $mockMediaModerationDatabaseLookup;
		$objectUnderTest->setBatchSize( $batchSize );
		$objectUnderTest->lastChecked = $lastChecked;
		$objectUnderTest->sha1ValuesBeingProcessed = [ 'sha-1-being-processed' ];
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
				// Whether the --use-jobqueue parameter is specified
				false,
				// Array of batches of SHA-1 values that are the mock return values of ::getSha1ValuesForScan
				[
					[ 'abc123', 'def456', 'cab321' ],
					[],
				],
				// The expected SHA-1 values returned in the Generator from the method under test.
				[ 'abc123', 'def456', 'cab321' ],
			],
			'Two batches of SHA-1 values with batch size as 4' => [
				4,
				true,
				[ [ 'abc123', 'def456', 'cab321', 'abcdef' ], [ 'abc1234', 'def4565', 'cab3212' ], [] ],
				[ 'abc123', 'def456', 'cab321', 'abcdef', 'abc1234', 'def4565', 'cab3212' ],
			],
			'No batches of SHA-1 values with batch size as 4' => [
				4, false, [ [] ], [],
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
			->onlyMethods( [ 'fatalError' ] )
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
		return [
			"Last checked as 'never'" => [ "never", null ],
			'Last checked not defined' => [ null, '20230503000000' ],
			'Last checked as YYYYMMDD' => [ '20220405', '20220405000000' ],
			'Last checked as a TS_MW timestamp' => [ '20230401020104', '20230401000000' ],
			'Last checked as TS_UNIX timestamp' => [ '2023-04-05 04:03:02', '20230405000000' ],
		];
	}

	/** @dataProvider provideParseLastCheckedTimestampOnInvalidLastChecked */
	public function testParseLastCheckedTimestampOnInvalidLastChecked(
		$lastCheckedOptionValue, $expectedErrorMessage
	) {
		// Fix the current time as the method under test calls ConvertibleTimestamp::time.
		ConvertibleTimestamp::setFakeTime( '20230504030201' );
		// The MediaModerationDatabaseLookup service is used only non-DB related methods when calling
		// ::parseLastCheckedTimestamp (the method under test).
		$mediaModerationDatabaseLookup = $this->newServiceInstance( MediaModerationDatabaseLookup::class, [] );
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'fatalError' ] )
			->getMock();
		$objectUnderTest->expects( $this->once() )
			->method( 'fatalError' )
			->with( $expectedErrorMessage );
		// Assign the $lastCheckedOptionValue to the be the 'last-checked' option value.
		$objectUnderTest->setOption( 'last-checked', $lastCheckedOptionValue );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mediaModerationDatabaseLookup = $mediaModerationDatabaseLookup;
		// Call the method under test
		$objectUnderTest->parseLastCheckedTimestamp();
	}

	public static function provideParseLastCheckedTimestampOnInvalidLastChecked() {
		return [
			'Unrecognised text' => [
				'testing',
				'The --last-checked argument passed to this script could not be parsed. This can take a ' .
				'timestamp in string form, or a date in YYYYMMDD format.',
			],
			'Unrecognised integer' => [
				'92034809235890348905839054324938590',
				'The --last-checked argument passed to this script could not be parsed. This can take a ' .
				'timestamp in string form, or a date in YYYYMMDD format.',
			],
			'The current date in TS_MW form' => [
				'20230504030201', 'The --last-checked argument cannot be the current date.'
			],
			'The current date in YYYYMMDD form' => [
				'20230504', 'The --last-checked argument cannot be the current date.'
			]
		];
	}

	public function testMaybeOutputVerboseScanResultWhenNotInVerboseMode() {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'output', 'error' ] )
			->getMock();
		// Expect that ::output and ::error are never called
		$objectUnderTest->expects( $this->never() )
			->method( 'output' );
		$objectUnderTest->expects( $this->never() )
			->method( 'error' );
		// Call the method under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->maybeOutputVerboseScanResult( 'abc1234', StatusValue::newGood( true ) );
	}

	/** @dataProvider provideMaybeOutputVerboseScanResultForStatusError */
	public function testMaybeOutputVerboseScanResultForNotGoodStatus(
		$sha1, $checkStatus, $expectedOutputStrings, $expectedErrorStrings
	) {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'error', 'output' ] )
			->getMock();
		$objectUnderTest->setOption( 'verbose', 1 );
		// Expect that ::output is called once or twice depending on the value
		// in $hasOutputtedSha1, and that the strings are as expected
		$objectUnderTest->expects( $this->exactly( count( $expectedErrorStrings ) ) )
			->method( 'error' )
			->willReturnCallback( function ( $actualErrorString ) use ( $expectedErrorStrings ) {
				$this->assertContains(
					$actualErrorString,
					$expectedErrorStrings,
					'::maybeOutputVerboseScanResult outputted an unexpected error string.'
				);
			} );
		$objectUnderTest->expects( $this->exactly( count( $expectedOutputStrings ) ) )
			->method( 'output' )
			->willReturnCallback( function ( $actualOutputString ) use ( $expectedOutputStrings ) {
				$this->assertContains(
					$actualOutputString,
					$expectedOutputStrings,
					'::maybeOutputVerboseScanResult outputted an unexpected string.'
				);
			} );
		// Define a mock StatusFormatter that returns a string in the same way that the actual
		// implementation formats the RawMessages.
		$mockStatusFormatter = $this->createMock( StatusFormatter::class );
		$mockStatusFormatter->method( 'getWikiText' )
			->willReturnCallback( static function ( StatusValue $status ) {
				if ( count( $status->getErrors() ) === 1 ) {
					/** @var RawMessage $rawMessage */
					$rawMessage = $status->getErrors()[0]['message'];
					return $rawMessage->fetchMessage();
				}
				$returnString = '';
				foreach ( $status->getErrors() as $rawError ) {
					/** @var RawMessage $rawMessage */
					$rawMessage = $rawError['message'];
					$returnString .= '* ' . $rawMessage->fetchMessage() . "\n";
				}
				return $returnString;
			} );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->statusFormatter = $mockStatusFormatter;
		// Call the method under test
		$objectUnderTest->maybeOutputVerboseScanResult( $sha1, $checkStatus );
	}

	public static function provideMaybeOutputVerboseScanResultForStatusError() {
		return [
			'Null match status with an error' => [
				'abc1234', StatusValue::newFatal( new RawMessage( "test" ) ),
				[], [ "SHA-1 abc1234\n", "* test\n", "SHA-1 abc1234: Scan failed.\n" ]
			],
			'Null match status with multiple errors' => [
				'abc1234',
				StatusValue::newFatal( new RawMessage( "test" ) )->fatal( new RawMessage( "test2" ) ),
				[], [ "SHA-1 abc1234\n", "* test\n* test2\n", "SHA-1 abc1234: Scan failed.\n" ]
			],
			'Positive match status without warnings' => [
				'abc123', StatusValue::newGood( true ), [ "SHA-1 abc123: Positive match.\n" ], [],
			],
			'Negative match status with warnings' => [
				'abc12345',
				StatusValue::newGood( false )->warning( new RawMessage( "test-warning" ) ),
				[ "SHA-1 abc12345: No match.\n" ], [ "SHA-1 abc12345\n", "* test-warning\n" ],
			],
		];
	}

	/** @dataProvider provideExecute */
	public function testExecute( array $sha1Values, $usesJobQueue ) {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [
				'initServices', 'parseLastCheckedTimestamp',
				'generateSha1ValuesForScan', 'maybeOutputVerboseScanResult'
			] )
			->getMock();
		// Define a mock for ::generateSha1ValuesForScan that returns the strings in $sha1Values
		$objectUnderTest->expects( $this->once() )
			->method( 'generateSha1ValuesForScan' )
			->willReturnCallback( static function () use ( $sha1Values ) {
				yield from $sha1Values;
			} );
		// Expect that ::initServices and ::parseLastCheckedTimestamp are called once
		$objectUnderTest->expects( $this->once() )
			->method( 'initServices' );
		$objectUnderTest->expects( $this->once() )
			->method( 'parseLastCheckedTimestamp' );
		// Define a mock JobQueueGroup
		$mockJobQueueGroup = $this->createMock( JobQueueGroup::class );
		$mockMediaModerationFileScanner = $this->createMock( MediaModerationFileScanner::class );
		if ( $usesJobQueue ) {
			// If $usesJobQueue is true, then set the --use-jobqueue option
			$objectUnderTest->setOption( 'use-jobqueue', 1 );
			// If using jobs, then no calls should be made to MediaModerationFileScanner::scanSha1
			$mockMediaModerationFileScanner->expects( $this->never() )
				->method( 'scanSha1' );
			$objectUnderTest->expects( $this->never() )
				->method( 'maybeOutputVerboseScanResult' );
			// If using the job queue, then expect calls to JobQueueGroup::push for each SHA-1
			$mockJobQueueGroup->expects( $this->exactly( count( $sha1Values ) ) )
				->method( 'push' )
				->willReturnCallback( function ( IJobSpecification $jobSpec ) use ( &$sha1Values ) {
					$this->assertSame(
						'mediaModerationScanFileJob',
						$jobSpec->getType(),
						'The job specification pushed to the JobQueueGroup was not as expected.'
					);
					$this->assertSame(
						$jobSpec->getParams()['sha1'],
						array_shift( $sha1Values ),
						'The job specification params pushed to the JobQueueGroup was not as expected.'
					);
				} );
		} else {
			// If not using jobs, then no jobs should be pushed.
			$mockJobQueueGroup->expects( $this->never() )
				->method( 'push' );
			// Expect that MediaModerationFileScanner::scanSha1 is called along with ::maybeOutputVerboseScanResult
			$exampleStatus = StatusValue::newFatal( new RawMessage( "test-test-test" ) );
			$mockMediaModerationFileScanner->expects( $this->exactly( count( $sha1Values ) ) )
				->method( 'scanSha1' )
				->willReturnCallback( function ( $sha1 ) use ( $sha1Values, $exampleStatus ) {
					$this->assertContains( $sha1, $sha1Values );
					return $exampleStatus;
				} );
			$objectUnderTest->expects( $this->exactly( count( $sha1Values ) ) )
				->method( 'maybeOutputVerboseScanResult' )
				->willReturnCallback( function ( $_, $scanStatus ) use ( $exampleStatus ) {
					$this->assertSame( $exampleStatus, $scanStatus );
				} );
		}
		// Assign the mock services to the object under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mediaModerationFileScanner = $mockMediaModerationFileScanner;
		$objectUnderTest->jobQueueGroup = $mockJobQueueGroup;
		// Call the method under test
		$objectUnderTest->execute();
	}

	public static function provideExecute() {
		return [
			'No SHA-1 values when using job queue' => [ [], true ],
			'No SHA-1 values' => [ [], false ],
			'A few SHA-1 values when using job queue' => [ [ 'test', 'test1234', 'abc' ], true ],
			'A few SHA-1 values' => [ [ 'test', 'test1234', 'abc' ], false ],
		];
	}

	public function testExecuteOnJobQueueError() {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'initServices', 'parseLastCheckedTimestamp', 'generateSha1ValuesForScan' ] )
			->getMock();
		// Define a mock for ::generateSha1ValuesForScan that returns some mock SHA-1 values
		$objectUnderTest->expects( $this->once() )
			->method( 'generateSha1ValuesForScan' )
			->willReturnCallback( static function () {
				yield from [ 'abc', '123' ];
			} );
		// Define a mock JobQueueGroup that will throw a JobQueueError exception.
		$mockJobQueueGroup = $this->createMock( JobQueueGroup::class );
		$mockJobQueueGroup->expects( $this->atLeastOnce() )
			->method( 'push' )
			->willThrowException( new JobQueueError( 'test-error' ) );
		$objectUnderTest->setOption( 'use-jobqueue', 1 );
		// Set 'sleep' option as 0 to prevent unit tests from running slowly.
		$objectUnderTest->setOption( 'sleep', 0 );
		// Assign the mock services to the object under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->jobQueueGroup = $mockJobQueueGroup;
		// Call the method under test
		$objectUnderTest->execute();
	}

	/** @dataProvider provideWaitForJobQueueSize */
	public function testWaitForJobQueueSize(
		$originalProcessingArray, $pollUntilArgument, $maxPollsArgument, $verboseArgumentEnabled, $batchParameter,
		$pollResponses, $expectedProcessingArraysAfterCall, $expectedOutputStrings, $expectedErrorStrings
	) {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'pollSha1ValuesForScanCompletion', 'output', 'error' ] )
			->getMock();
		// Mock ::pollSha1ValuesForScanCompletion to return the items in $pollResponses in order.
		$objectUnderTest->expects( $this->exactly( count( $pollResponses ) ) )
			->method( 'pollSha1ValuesForScanCompletion' )
			->willReturnOnConsecutiveCalls( ...$pollResponses );
		// Expect that ::output is called with the $expectedOutputStrings
		$objectUnderTest->expects( $this->exactly( count( $expectedOutputStrings ) ) )
			->method( 'output' )
			->willReturnCallback( function ( $actualOutputString ) use ( $expectedOutputStrings ) {
				$this->assertContains(
					$actualOutputString,
					$expectedOutputStrings,
					'::output was called with an unexpected string by ::waitForJobQueueSize'
				);
			} );
		// Expect that ::error is called with the $expectedErrorStrings
		$objectUnderTest->expects( $this->exactly( count( $expectedErrorStrings ) ) )
			->method( 'error' )
			->willReturnCallback( function ( $actualOutputString ) use ( $expectedErrorStrings ) {
				$this->assertContains(
					$actualOutputString,
					$expectedErrorStrings,
					'::error was called with an unexpected string by ::waitForJobQueueSize'
				);
			} );
		// Set the poll-sleep time to 0 for the test (otherwise the test will take several seconds)
		$objectUnderTest->setOption( 'poll-sleep', 0 );
		// Set the poll-until as $pollUntilArgument unless $pollUntilArgument is null. $pollUntilArgument as null
		// indicates that the option was not set (and so to use the default).
		if ( $pollUntilArgument !== null ) {
			$objectUnderTest->setOption( 'poll-until', $pollUntilArgument );
		}
		if ( $maxPollsArgument !== null ) {
			$objectUnderTest->setOption( 'max-polls', $maxPollsArgument );
		}
		if ( $verboseArgumentEnabled ) {
			$objectUnderTest->setOption( 'verbose', 1 );
		}
		// Set --batch-size as the value is used in calculating the default of --poll-until
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mBatchSize = count( $batchParameter );
		// Set the internal array to $originalProcessingArray
		$objectUnderTest->sha1ValuesBeingProcessed = $originalProcessingArray;
		// Call the method under test
		$objectUnderTest->waitForJobQueueSize( $batchParameter );
		// Assert that the internal array is as expected
		$this->assertArrayEquals(
			$expectedProcessingArraysAfterCall,
			$objectUnderTest->sha1ValuesBeingProcessed,
			'The SHA-1s still being processed array is not as expected.'
		);
	}

	public static function provideWaitForJobQueueSize() {
		return [
			'Originally empty internal array, 5 SHA-1s in batch, with default poll-until' => [
				// The value of the sha1ValuesBeingProcessed property before calling the method under test
				[],
				// The value of --poll-until. Null indicates the value is not set.
				null,
				// The value of --max-polls. Null indicates the value is not set.
				null,
				// Whether the --verbose argument has been specified,
				false,
				// The array provided to the method under test
				[ 'test', 'testabc', 'testabc1', 'testabc12', 'testabc123' ],
				// The return values from ::pollSha1ValuesForScanCompletion in order of call
				[ [ 'test' ], [ 'testabc12', 'testabc123' ] ],
				// The expected value of the sha1ValuesBeingProcessed property after calling the method under test
				[ 'testabc', 'testabc1' ],
				// An array of strings expected to be provided to ::output by the method under test
				[],
				// An array of strings expected to be provided to ::error by the method under test
				[],
			],
			'12 items in internal array before call, 1 SHA-1 in batch, poll-until as 5, verbose mode' => [
				range( 'a', 'l' ), 5, null, true, [ 'testabc' ], [ range( 'a', 'g' ), [ 'testabc' ] ],
				range( 'h', 'l' ),
				[
					"Added 1 SHA-1 value(s) for scanning via the job queue: testabc\n",
					'13 SHA-1 value(s) currently being processed via jobs. Waiting until there are 5 or less SHA-1 ' .
					"value(s) being processed before adding more jobs.\n",
					'6 SHA-1 value(s) currently being processed via jobs. Waiting until there are 5 or less SHA-1 ' .
					"value(s) being processed before adding more jobs.\n",
				], [],
			],
			'10 items in internal array before call, 1 SHA-1 in batch, max-polls as 2' => [
				range( 'a', 'j' ), null, 2, false, [ 'testabc' ], [ range( 'a', 'g' ), [ 'testabc' ] ], [],
				[], [],
			],
			'10 items in internal array before call, 2 SHA-1s in batch, max-polls as 2, verbose mode' => [
				range( 'a', 'j' ), null, 2, true, [ 'testabc', 'test' ], [ range( 'a', 'g' ), [ 'testabc' ] ], [],
				[
					"Added 2 SHA-1 value(s) for scanning via the job queue: testabc, test\n",
					'12 SHA-1 value(s) currently being processed via jobs. Waiting until there are 1 or less SHA-1 ' .
					"value(s) being processed before adding more jobs.\n",
					'5 SHA-1 value(s) currently being processed via jobs. Waiting until there are 1 or less SHA-1 ' .
					"value(s) being processed before adding more jobs.\n"
				],
				[
					'The internal array of SHA-1 values being processed has been cleared as more than ' .
					"2 polls have occurred.\n"
				],
			],
			'No items in internal array before call, no items in batch' => [
				[], null, null, false, [], [], [], [], [],
			],
			'No items in internal array before call, no items in batch, verbose mode' => [
				[], null, null, true, [], [], [],
				[
					"Added 0 SHA-1 value(s) for scanning via the job queue: \n"
				], [],
			]
		];
	}

	public function testPollSha1ValuesForScanCompletionWhenNoSha1ValuesBeingProcessed() {
		// The internal array of SHA-1 values being processed is initially set to an empty array. When
		// ::pollSha1ValuesForScanCompletion with the internal array being empty, it should return an empty
		// array without attempting to perform DB queries.
		$objectUnderTest = TestingAccessWrapper::newFromObject( new ScanFilesInScanTable() );
		$this->assertArrayEquals(
			[], $objectUnderTest->pollSha1ValuesForScanCompletion(),
			'::pollSha1ValuesForScanCompletion did not return the expected array.'
		);
	}

	public function testPollSha1ValuesForScanCompletion() {
		$dbrMock = $this->createMock( IReadableDatabase::class );
		$mockExpression = $this->createMock( Expression::class );
		$dbrMock->method( 'expr' )
			->with( 'mms_last_checked', '>', '20230504' )
			->willReturn( $mockExpression );
		// Create a SelectQueryBuilder that has a mock ::fetchFieldValues
		$mockSelectQueryBuilder = $this->getMockBuilder( SelectQueryBuilder::class )
			->setConstructorArgs( [ $dbrMock ] )
			->onlyMethods( [ 'fetchFieldValues' ] )
			->getMock();
		$mockSelectQueryBuilder->expects( $this->once() )
			->method( 'fetchFieldValues' )
			->willReturn( [ 'test', 'test1234' ] );
		// Implement $dbrMock::newSelectQueryBuilder to return the $mockSelectQueryBuilder
		$dbrMock->method( 'newSelectQueryBuilder' )->willReturn( $mockSelectQueryBuilder );
		// Create a mock IConnectionProvider that returns the $dbrMock from ::getReplicaDatabase
		$mockConnectionProvider = $this->createMock( IConnectionProvider::class );
		$mockConnectionProvider->method( 'getReplicaDatabase' )
			->willReturn( $dbrMock );
		// Get the object under test, with a mocked ::waitForReplication method (to avoid calls to the service
		// container).
		$objectUnderTest = $this->getMockBuilder( ScanFilesInScanTable::class )
			->onlyMethods( [ 'waitForReplication' ] )
			->getMock();
		$objectUnderTest->expects( $this->atLeastOnce() )
			->method( 'waitForReplication' );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		// Create a new instance of the MediaModerationDatabaseLookup service using the mock connection provider
		// and assign it to the object under test.
		$objectUnderTest->mediaModerationDatabaseLookup = new MediaModerationDatabaseLookup( $mockConnectionProvider );
		// Set the internal SHA-1 processing array to a defined value
		$objectUnderTest->sha1ValuesBeingProcessed = [ 'test', 'testabc' ];
		// Set lastChecked
		$objectUnderTest->lastChecked = '20230504000000';
		// Call the method under test
		$this->assertArrayEquals(
			[ 'test', 'test1234' ],
			$objectUnderTest->pollSha1ValuesForScanCompletion(),
			'::pollSha1ValuesForScanCompletion did not return the expected array.'
		);
		// Assert that the $mockSelectQueryBuilder has the expected query info
		$this->assertArrayEquals(
			[ 'mms_sha1' ],
			$mockSelectQueryBuilder->getQueryInfo()['fields'],
			'The fields used in a poll were not as expected'
		);
		$this->assertArrayEquals(
			[ 'mms_sha1' => [ 'test', 'testabc' ], $mockExpression ],
			$mockSelectQueryBuilder->getQueryInfo()['conds'],
			'The conditions used in a poll were not as expected'
		);
	}
}
