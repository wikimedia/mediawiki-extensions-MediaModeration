<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\PeriodicMetrics;

use MediaWiki\Extension\MediaModeration\PeriodicMetrics\IMetric;
use MediaWiki\Extension\MediaModeration\PeriodicMetrics\TotalTableCountMetric;
use MediaWiki\Extension\MediaModeration\PeriodicMetrics\UnscannedImagesMetric;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @covers \MediaWiki\Extension\MediaModeration\PeriodicMetrics\UnscannedImagesMetric
 * @covers \MediaWiki\Extension\MediaModeration\PeriodicMetrics\TotalTableCountMetric
 * @group MediaModeration
 */
class IMetricImplementationTest extends MediaWikiUnitTestCase {

	use MockServiceDependenciesTrait;

	/** @dataProvider provideCalculate */
	public function testCalculate( $className, $expectedConds ) {
		// Create a mock IReadableDatabase that will return a mock SelectQueryBuilder
		$mockDbr = $this->createMock( IReadableDatabase::class );
		// Mock the SelectQueryBuilder to return a fake row count from ::fetchField
		$mockSelectQueryBuilder = $this->getMockBuilder( SelectQueryBuilder::class )
			->setConstructorArgs( [ $mockDbr ] )
			->onlyMethods( [ 'fetchField' ] )
			->getMock();
		$mockSelectQueryBuilder->method( 'fetchField' )
			->willReturn( 123 );
		$mockDbr->method( 'newSelectQueryBuilder' )
			->willReturn( $mockSelectQueryBuilder );
		// Get the object under test and call the method under test
		/** @var IMetric $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( $className, [ 'dbr' => $mockDbr ] );
		$this->assertSame( 123, $objectUnderTest->calculate() );
		// Expect that the query performed was correct by validating the fields, tables, and WHERE conditions.
		$this->assertArrayEquals(
			[ 'mediamoderation_scan' ],
			$mockSelectQueryBuilder->getQueryInfo()['tables'],
			'Table for the performed query was not as expected'
		);
		$this->assertArrayEquals(
			[ 'COUNT(*)' ],
			$mockSelectQueryBuilder->getQueryInfo()['fields'],
			'Select fields for the performed query was not as expected'
		);
		$this->assertArrayEquals(
			$expectedConds,
			$mockSelectQueryBuilder->getQueryInfo()['conds'],
			'WHERE conditions for the performed query was not as expected'
		);
	}

	public static function provideCalculate() {
		return [
			'TotalTableCountMetric' => [ TotalTableCountMetric::class, [] ],
			'UnscannedImagesMetric' => [ UnscannedImagesMetric::class, [ 'mms_is_match' => null ] ],
		];
	}
}
