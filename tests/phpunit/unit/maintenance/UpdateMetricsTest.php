<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Maintenance;

use InvalidArgumentException;
use MediaWiki\Extension\MediaModeration\Maintenance\UpdateMetrics;
use MediaWiki\Extension\MediaModeration\PeriodicMetrics\MediaModerationMetricsFactory;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\UpdateMetrics
 * @group MediaModeration
 */
class UpdateMetricsTest extends MediaWikiUnitTestCase {
	public function testExecuteOnInvalidArgumentExceptionFromNewMetricMethod() {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( UpdateMetrics::class )
			->onlyMethods( [ 'initServices', 'error' ] )
			->getMock();
		// Mock the MediaModerationMetricsFactory::newMetric method to throw an InvalidArgumentException
		$mockMediaModerationMetricsFactory = $this->createMock( MediaModerationMetricsFactory::class );
		$mockMediaModerationMetricsFactory->method( 'newMetric' )
			->willThrowException( new InvalidArgumentException() );
		// Define a mock logger that expects ::error to be called.
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->exactly( count( MediaModerationMetricsFactory::METRICS ) ) )
			->method( 'error' );
		// Expect that UpdateMetrics::error is called
		$objectUnderTest->expects( $this->exactly( count( MediaModerationMetricsFactory::METRICS ) ) )
			->method( 'error' );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mediaModerationMetricsFactory = $mockMediaModerationMetricsFactory;
		$objectUnderTest->logger = $mockLogger;
		// Call the method under test
		$objectUnderTest->execute();
	}
}
