<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\PeriodicMetrics;

use InvalidArgumentException;
use MediaWiki\Extension\MediaModeration\PeriodicMetrics\MediaModerationMetricsFactory;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\MediaModeration\PeriodicMetrics\MediaModerationMetricsFactory
 * @group MediaModeration
 */
class MediaModerationMetricsFactoryTest extends MediaWikiUnitTestCase {

	use MockServiceDependenciesTrait;

	public function testNewMetricOnInvalidMetric() {
		// Expect a InvalidArgumentException is thrown.
		$this->expectException( InvalidArgumentException::class );
		/** @var MediaModerationMetricsFactory $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( MediaModerationMetricsFactory::class, [] );
		// Call the method under test
		$objectUnderTest->newMetric( 'testing' );
	}

	/** @dataProvider provideMetricClasses */
	public function testNewMetric( $className ) {
		/** @var MediaModerationMetricsFactory $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( MediaModerationMetricsFactory::class, [] );
		// Call the method under test
		$this->assertInstanceOf(
			$className,
			$objectUnderTest->newMetric( $className ),
			'::newMetric returned an object where the class name was not as expected.'
		);
	}

	public static function provideMetricClasses() {
		// Yield all metric classes defined in MediaModerationMetricsFactory::METRICS.
		foreach ( MediaModerationMetricsFactory::METRICS as $metric ) {
			yield $metric => [ $metric ];
		}
	}
}
