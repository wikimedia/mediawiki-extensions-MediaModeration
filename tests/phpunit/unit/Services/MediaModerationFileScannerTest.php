<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Services;

use File;
use MediaWiki\Extension\MediaModeration\PhotoDNA\Response;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseManager;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileProcessor;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileScanner;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationPhotoDNAServiceProvider;
use MediaWiki\Status\StatusFormatter;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationFileScanner
 * @group MediaModeration
 */
class MediaModerationFileScannerTest extends MediaWikiUnitTestCase {

	use MockServiceDependenciesTrait;

	/** @dataProvider provideScanSha1 */
	public function testScanSha1(
		$mockOldMatchStatus, $numberOfFileObjects, array $canScanFileResults, array $mockPhotoDNAResponses,
		$expectStatusToBeGood, $expectStatusToBeOkay, $expectedNewMatchStatus
	) {
		$sha1 = 'testing1234';
		// Define a mock MediaModerationDatabaseLookup that expects to be called and will return $mockOldMatchStatus
		$mockMediaModerationDatabaseLookup = $this->createMock( MediaModerationDatabaseLookup::class );
		$mockMediaModerationDatabaseLookup->expects( $this->once() )
			->method( 'getMatchStatusForSha1' )
			->with( $sha1 )
			->willReturn( $mockOldMatchStatus );
		// Define a mock MediaModerationFileLookup service that will yield
		// mock File objects from ::getFileObjectsForSha1
		$mockMediaModerationFileLookup = $this->createMock( MediaModerationFileLookup::class );
		$mockMediaModerationFileLookup->expects( $this->once() )
			->method( 'getFileObjectsForSha1' )
			->with( $sha1 )
			->willReturnCallback( function () use ( $numberOfFileObjects ) {
				for ( $i = 0; $i < $numberOfFileObjects; $i++ ) {
					$mockFile = $this->createMock( File::class );
					$mockFile->method( 'getName' )
						->willReturn( 'Test.png' );
					yield $mockFile;
				}
			} );
		// Create a mock MediaModerationFileProcessor that will return the values from $canScanFileResults in order
		$mockMediaModerationFileProcessor = $this->createMock( MediaModerationFileProcessor::class );
		$mockMediaModerationFileProcessor->expects( $this->exactly( count( $canScanFileResults ) ) )
			->method( 'canScanFile' )
			->willReturnOnConsecutiveCalls( ...$canScanFileResults );
		// Create a mock MediaModerationPhotoDNAServiceProvider that returns the values from $checkResults in order
		$mockMediaModerationPhotoDNAServiceProvider = $this->createMock(
			MediaModerationPhotoDNAServiceProvider::class
		);
		$mockMediaModerationPhotoDNAServiceProvider->expects( $this->exactly( count( $mockPhotoDNAResponses ) ) )
			->method( 'check' )
			->willReturnCallback( static function () use ( &$mockPhotoDNAResponses ) {
				return StatusValue::newGood( array_shift( $mockPhotoDNAResponses ) );
			} );
		// Expect that MediaModerationDatabaseManager is always called
		$mockMediaModerationDatabaseManager = $this->createMock( MediaModerationDatabaseManager::class );
		$mockMediaModerationDatabaseManager->expects( $this->once() )
			->method( 'updateMatchStatusForSha1' )
			->with( $sha1, $expectedNewMatchStatus ?? $mockOldMatchStatus );
		// Get a mock LoggerInterface and StatusFormatter. Expect calls to the mock logger depending
		// on the expected state of the returned status.
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockStatusFormatter = $this->createMock( StatusFormatter::class );
		if ( $expectStatusToBeGood ) {
			// No logging should occur if there were no errors or warnings in the status.
			$mockLogger->expects( $this->never() )
				->method( 'debug' );
			$mockLogger->expects( $this->never() )
				->method( 'info' );
			$mockStatusFormatter->expects( $this->never() )
				->method( 'getMessage' );
		} elseif ( $expectStatusToBeOkay ) {
			// A debug log should occur if there were warnings in the status.
			$mockLogger->expects( $this->never() )
				->method( 'log' );
			$mockLogger->expects( $this->once() )
				->method( 'debug' )
				->with(
					'Scan of SHA-1 {sha1} succeeded with warnings. MediaModerationFileScanner::scanSha1 ' .
					'returned this: {return-message}',
					[
						'sha1' => $sha1,
						'return-message' => 'mock-info-message',
					]
				);
			$mockStatusFormatter->expects( $this->once() )
				->method( 'getMessage' )
				->willReturn( 'mock-info-message' );
		} else {
			// A info log should occur if there were errors and no scan could be completed.
			$mockLogger->expects( $this->once() )
				->method( 'info' )
				->with(
					'Unable to scan SHA-1 {sha1}. MediaModerationFileScanner::scanSha1 returned this: {return-message}',
					[
						'sha1' => $sha1,
						'return-message' => 'mock-warning-message',
					]
				);
			$mockLogger->expects( $this->never() )
				->method( 'debug' );
			$mockStatusFormatter->expects( $this->once() )
				->method( 'getMessage' )
				->willReturn( 'mock-warning-message' );
		}
		// Get the object under test
		/** @var MediaModerationFileScanner $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			MediaModerationFileScanner::class,
			[
				'mediaModerationDatabaseLookup' => $mockMediaModerationDatabaseLookup,
				'mediaModerationDatabaseManager' => $mockMediaModerationDatabaseManager,
				'mediaModerationFileLookup' => $mockMediaModerationFileLookup,
				'mediaModerationFileProcessor' => $mockMediaModerationFileProcessor,
				'mediaModerationPhotoDNAServiceProvider' => $mockMediaModerationPhotoDNAServiceProvider,
				'statusFormatter' => $mockStatusFormatter,
				'logger' => $mockLogger,
			]
		);
		// Call the method under test
		$actualStatus = $objectUnderTest->scanSha1( $sha1 );
		if ( $expectStatusToBeGood ) {
			$this->assertStatusGood(
				$actualStatus,
				'The StatusValue returned by ::scanSha1 should have been good.'
			);
		} elseif ( $expectStatusToBeOkay ) {
			$this->assertStatusOK(
				$actualStatus,
				'The StatusValue returned by ::scanSha1 should have been okay.'
			);
		} else {
			$this->assertStatusNotOK(
				$actualStatus,
				'The StatusValue returned by ::scanSha1 should have been not okay.'
			);
		}
		$this->assertStatusValue(
			$expectedNewMatchStatus,
			$actualStatus,
			'The StatusValue returned by ::scanSha1 did not have the expected value.'
		);
	}

	public static function provideScanSha1() {
		return [
			'No File objects were found' => [
				// The old match status as returned by MediaModerationDatabaseLookup::getMatchStatusForSha1
				null,
				// Number of File/ArchivedFile objects found by MediaModerationFileLookup::getFileObjectsForSha1
				0,
				// An array of responses from MediaModerationFileProcessor::canScanFile in order
				[],
				// An array of responses from IMediaModerationPhotoDNAServiceProvider::check in order
				[],
				// Whether the returned status should be good
				false,
				// Whether the returned status should be okay
				false,
				// The expected new match status
				null,
			],
			'One File object that cannot be scanned for SHA-1 which already has been matched' => [
				true, 1, [ false ], [], false, false, null,
			],
			'One File object that can be scanned but PhotoDNA rejects' => [
				false,
				1,
				[ true ],
				[ new Response( Response::STATUS_COULD_NOT_VERIFY_FILE_AS_IMAGE ) ],
				false,
				false,
				null,
			],
			'One File object that PhotoDNA accepts the scan on' => [
				null, 1, [ true ], [ new Response( Response::STATUS_OK ) ], true, true, false,
			],
			'Multiple File objects with last being accepted by PhotoDNA' => [
				null,
				4,
				[ false, true, true, true ],
				[
					new Response( Response::STATUS_COULD_NOT_VERIFY_FILE_AS_IMAGE ),
					new Response( Response::STATUS_IMAGE_PIXEL_SIZE_NOT_IN_RANGE ),
					new Response( Response::STATUS_OK, true )
				],
				false,
				true,
				true,
			],
		];
	}
}
