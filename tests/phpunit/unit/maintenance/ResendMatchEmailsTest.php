<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Maintenance;

use InvalidArgumentException;
use MediaWiki\Extension\MediaModeration\Maintenance\ResendMatchEmails;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\Expression;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\ResendMatchEmails
 * @group MediaModeration
 */
class ResendMatchEmailsTest extends MediaWikiUnitTestCase {

	/** @dataProvider provideParseTimestampsForFatalError */
	public function testParseTimestampsForFatalError( $scannedSince, $uploadedSince, $expectedFatalErrorMessage ) {
		$this->expectException( InvalidArgumentException::class );
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( ResendMatchEmails::class )
			->onlyMethods( [ 'fatalError' ] )
			->getMock();
		// Add the argument and option
		$objectUnderTest->setArg( 'scanned-since', $scannedSince );
		$objectUnderTest->setOption( 'uploaded-since', $uploadedSince );
		// Expect that ::fatalError is called.
		$objectUnderTest->expects( $this->once() )
			->method( 'fatalError' )
			->with( $expectedFatalErrorMessage )
			// Needed because ::fatalError would cause the script to exit, but our mock does not do that so throwing
			// an Exception works here instead.
			->willThrowException( new InvalidArgumentException( 'Test' ) );
		// Call the method under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->parseTimestamps();
	}

	public static function provideParseTimestampsForFatalError() {
		return [
			'Scanned since is not a string' => [
				// The scanned-since argument
				null,
				// The --uploaded-since option
				'20230506050403',
				// The expected error message passed to ::fatalError
				'The scanned-since argument must be a string.'
			],
			'Scanned since is not a valid timestamp or YYYYMMDD date' => [
				// The scanned-since argument
				'abcef',
				// The --uploaded-since option
				'20230506050403',
				// The expected error message passed to ::fatalError
				'The scanned-since argument passed to this script could not be parsed. This can take a ' .
				'timestamp in string form, or a date in YYYYMMDD format.'
			],
			'Uploaded since is not a valid timestamp' => [
				// The scanned-since argument
				'20230506',
				// The --uploaded-since option
				'abdef',
				// The expected error message passed to ::fatalError
				'The uploaded-since timestamp could not be parsed as a valid timestamp'
			],
		];
	}

	public function testGetSelectQueryBuilder() {
		$scannedSince = '20230405';
		$previousBatchLastSha1Value = 'abcdef';
		$mockExpression = $this->createMock( Expression::class );
		// Create a mock IReadableDatabase that implements ::expr and ::newSelectQueryBuilder
		$mockDbr = $this->createMock( IReadableDatabase::class );
		$mockDbr->method( 'expr' )
			->willReturnCallback( function ( $field, $operator, $value ) use (
				$mockExpression, $scannedSince, $previousBatchLastSha1Value
			) {
				if ( $field === 'mms_last_checked' ) {
					$this->assertSame( '>=', $operator, 'Unexpected operator passed to ::expr' );
					$this->assertSame( (int)$scannedSince, $value, 'Unexpected value passed to ::expr' );
				} elseif ( $field === 'mms_sha1' ) {
					$this->assertSame( '>', $operator, 'Unexpected operator passed to ::expr' );
					$this->assertSame( $previousBatchLastSha1Value, $value, 'Unexpected value passed to ::expr' );
				} else {
					$this->fail( 'Unexpected field passed to IReadableDatabase::expr' );
				}
				return $mockExpression;
			} );
		$mockDbr->method( 'newSelectQueryBuilder' )
			->willReturnCallback( static function () use ( $mockDbr ) {
				return new SelectQueryBuilder( $mockDbr );
			} );
		// Create a mock MediaModerationDatabaseLookup that returns the $mockDbr from ::getDb
		$mockMediaModerationDatabaseLookup = $this->createMock( MediaModerationDatabaseLookup::class );
		$mockMediaModerationDatabaseLookup->method( 'getDb' )
			->willReturn( $mockDbr );
		// Get the object under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( new ResendMatchEmails() );
		$objectUnderTest->scannedSince = $scannedSince;
		$objectUnderTest->mediaModerationDatabaseLookup = $mockMediaModerationDatabaseLookup;
		// Call the method under test
		/** @var SelectQueryBuilder $actualSelectQueryBuilder */
		$actualSelectQueryBuilder = $objectUnderTest->getSelectQueryBuilder( $previousBatchLastSha1Value );
		// Expect that the query info of the $actualSelectQueryBuilder is as expected
		$this->assertArrayEquals(
			[
				'fields' => [ 'mms_sha1' ],
				'conds' => [ $mockExpression, 'mms_is_match' => 1, $mockExpression ],
				'join_conds' => [],
				'tables' => [ 'mediamoderation_scan' ],
				'options' => [ 'LIMIT' => 200, 'ORDER BY' => [ 'mms_sha1 ASC' ] ],
			],
			$actualSelectQueryBuilder->getQueryInfo(),
			false,
			true,
			'Fields in the SelectQueryBuilder returned by ::getSelectQueryBuilder were not as expected.'
		);
	}
}
