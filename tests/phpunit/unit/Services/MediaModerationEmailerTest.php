<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Services;

use File;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationEmailer;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup;
use MediaWiki\Language\RawMessage;
use MediaWiki\Mail\IEmailer;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationEmailer
 * @group MediaModeration
 */
class MediaModerationEmailerTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	public function testGetEmailBodyFooterTextOnNoMissingTimestamps() {
		// Get the object under test.
		$objectUnderTest = $this->newServiceInstance( MediaModerationEmailer::class, [] );
		// Call the method under test with an empty array and expects that it returns an empty string.
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			'',
			$objectUnderTest->getEmailBodyFooterText( [] )
		);
	}

	/** @dataProvider provideGetFileObjectsGroupedByFileName */
	public function testGetFileObjectsGroupedByFileName(
		$fileObjectsFileNamesAndTimestamps, $minimumTimestamp, $expectedGroupedFileTimestamps
	) {
		// Convert $fileObjectsFileNamesAndTimestamps to an array of mock File objects
		$mockFileObjects = [];
		foreach ( $fileObjectsFileNamesAndTimestamps as $fileObjectEntry ) {
			$mockFile = $this->createMock( File::class );
			$mockFile->method( 'getTimestamp' )
				->willReturn( $fileObjectEntry['timestamp'] );
			$mockFile->method( 'getName' )
				->willReturn( $fileObjectEntry['name'] );
			$mockFileObjects[] = $mockFile;
		}
		// Mock MediaModerationFileLookup::getFileObjectsForSha1 to return $mockFileObjects
		$mockMediaModerationFileLookup = $this->createMock( MediaModerationFileLookup::class );
		$mockMediaModerationFileLookup->method( 'getFileObjectsForSha1' )
			->with( 'mock-sha1', 50 )
			->willReturnCallback( static function () use ( $mockFileObjects ) {
				yield from $mockFileObjects;
			} );
		// Create the object under test
		$objectUnderTest = $this->newServiceInstance( MediaModerationEmailer::class, [
			'mediaModerationFileLookup' => $mockMediaModerationFileLookup,
		] );
		// Call the method under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$actualGroupedFileObjects = $objectUnderTest->getFileObjectsGroupedByFileName( 'mock-sha1', $minimumTimestamp );
		$actualGroupedFileTimestamps = [];
		foreach ( $actualGroupedFileObjects as $fileName => $fileObjects ) {
			$actualGroupedFileTimestamps[$fileName] = [];
			$this->assertIsArray( $fileObjects );
			foreach ( $fileObjects as $fileObject ) {
				$this->assertSame(
					$fileName,
					$fileObject->getName(),
					'A File object was incorrectly grouped by ::getFileObjectsGroupedByFileName'
				);
				$actualGroupedFileTimestamps[$fileName][] = $fileObject->getTimestamp();
			}
		}
		$this->assertArrayEquals(
			$expectedGroupedFileTimestamps,
			$actualGroupedFileTimestamps,
			false,
			true,
			'::getFileObjectsGroupedByFileName did not group the File objects in the expected way.'
		);
	}

	public static function provideGetFileObjectsGroupedByFileName() {
		return [
			'One filename with no missing timestamps and no minimum timestamp' => [
				// The expected results of ::getTimestamp and ::getName for each File object returned by
				// MediaModerationFileLookup::getFileObjectsForSha1
				[
					[ 'name' => 'Test.png', 'timestamp' => '20230405060708' ],
					[ 'name' => 'Test.png', 'timestamp' => '20240405060708' ],
				],
				// The $minimumTimestamp argument passed to the method under test
				null,
				// The expected return array with the File objects replaced with the expected result of their
				// ::getTimestamp method.
				[ 'Test.png' => [ '20230405060708', '20240405060708' ] ],
			],
			'One filename with no missing timestamps and a minimum timestamp' => [
				[
					[ 'name' => 'Test.png', 'timestamp' => '20230405060708' ],
					[ 'name' => 'Test.png', 'timestamp' => '20240405060708' ],
				],
				'20240105060708', [ 'Test.png' => [ '20240405060708' ] ],
			],
			'Multiple filenames with a missing timestamp and no a minimum timestamp' => [
				[
					[ 'name' => 'Test.png', 'timestamp' => '20220405060708' ],
					[ 'name' => 'Test.png', 'timestamp' => '20230405060708' ],
					[ 'name' => 'Test.png', 'timestamp' => '20240405060708' ],
					[ 'name' => 'Test2.png', 'timestamp' => false ],
				],
				null,
				[ 'Test.png' => [ '20220405060708', '20230405060708', '20240405060708' ], 'Test2.png' => [ false ] ],
			],
			'Multiple filenames with missing timestamps and a minimum timestamp' => [
				[
					[ 'name' => 'Test.png', 'timestamp' => false ],
					[ 'name' => 'Test.png', 'timestamp' => '20240405060708' ],
					[ 'name' => 'Test.png', 'timestamp' => '20220405060708' ],
					[ 'name' => 'Test2.png', 'timestamp' => false ],
				],
				'20240105060708',
				[ 'Test.png' => [ false, '20240405060708' ], 'Test2.png' => [ false ] ],
			],
		];
	}

	/** @dataProvider provideMatchStatusesOtherThanPositive */
	public function testSendEmailForSha1WhenNotAMatch( $matchStatus ) {
		// Create a mock MediaModerationDatabaseLookup that will return $matchStatus from ::getMatchStatusForSha1
		$mockMediaModerationDatabaseLookup = $this->createMock( MediaModerationDatabaseLookup::class );
		$mockMediaModerationDatabaseLookup->method( 'getMatchStatusForSha1' )
			->willReturn( $matchStatus );
		// Create a mock Logger that expects ::error is called.
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->with(
				'Attempted to send email for SHA-1 {sha1} that was not a match.',
				[ 'sha1' => 'test-sha1' ]
			);
		// Create the object under test
		$objectUnderTest = $this->newServiceInstance( MediaModerationEmailer::class, [
			'mediaModerationDatabaseLookup' => $mockMediaModerationDatabaseLookup,
			'logger' => $mockLogger,
		] );
		// Call the method under test
		$actualEmailerStatus = $objectUnderTest->sendEmailForSha1( 'test-sha1' );
		$this->assertStatusNotOK( $actualEmailerStatus );
	}

	public static function provideMatchStatusesOtherThanPositive() {
		return [
			'Null match status' => [ MediaModerationDatabaseLookup::NULL_MATCH_STATUS ],
			'Negative match status' => [ (bool)MediaModerationDatabaseLookup::NEGATIVE_MATCH_STATUS ],
		];
	}

	public function testSendEmailForSha1OnBadEmail() {
		$mockEmailerStatus = StatusValue::newFatal( new RawMessage( 'test' ) );
		// Create a mock IEmailer that returns $mockEmailerStatus from ::send
		$mockEmailer = $this->createMock( IEmailer::class );
		$mockEmailer->method( 'send' )
			->willReturn( $mockEmailerStatus );
		// Create a mock MediaModerationDatabaseLookup that will return true from ::getMatchStatusForSha1
		$mockMediaModerationDatabaseLookup = $this->createMock( MediaModerationDatabaseLookup::class );
		$mockMediaModerationDatabaseLookup->method( 'getMatchStatusForSha1' )
			->willReturn( true );
		// Create a mock Logger that expects ::error is called.
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'critical' )
			->with(
				'Email indicating SHA-1 match failed to send. SHA-1: {sha1}',
				[ 'sha1' => 'test-sha1', 'status' => $mockEmailerStatus ]
			);
		// Create the object under test
		$objectUnderTest = $this->getMockBuilder( MediaModerationEmailer::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getEmailBodyPlaintext', 'getEmailBodyHtml', 'getEmailSubject' ] )
			->getMock();
		$objectUnderTest->method( 'getEmailBodyHtml' )
			->willReturn( 'html-body' );
		$objectUnderTest->method( 'getEmailBodyPlaintext' )
			->willReturn( 'plaintext-body' );
		$objectUnderTest->method( 'getEmailSubject' )
			->willReturn( 'email-subject' );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->options = new ServiceOptions(
			MediaModerationEmailer::CONSTRUCTOR_OPTIONS,
			new HashConfig( [
				'MediaModerationRecipientList' => [ 'test@test.com' ],
				'MediaModerationFrom' => 'testing@test.com',
				MainConfigNames::Sitename => 'test',
			] )
		);
		$objectUnderTest->mediaModerationDatabaseLookup = $mockMediaModerationDatabaseLookup;
		$objectUnderTest->emailer = $mockEmailer;
		$objectUnderTest->logger = $mockLogger;
		// Call the method under test
		$actualEmailerStatus = $objectUnderTest->sendEmailForSha1( 'test-sha1' );
		$this->assertStatusNotOK( $actualEmailerStatus );
	}
}
