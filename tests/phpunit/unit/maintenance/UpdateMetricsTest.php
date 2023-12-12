<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\Maintenance;

use InvalidArgumentException;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Extension\MediaModeration\Maintenance\UpdateMetrics;
use MediaWiki\Extension\MediaModeration\PeriodicMetrics\IMetric;
use MediaWiki\Extension\MediaModeration\PeriodicMetrics\MediaModerationMetricsFactory;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\UpdateMetrics
 * @group MediaModeration
 */
class UpdateMetricsTest extends MediaWikiUnitTestCase {
	/** @dataProvider provideVerboseEnabled */
	public function testExecute( $verboseEnabled ) {
		// Get the object under test
		$objectUnderTest = $this->getMockBuilder( UpdateMetrics::class )
			->onlyMethods( [ 'initServices', 'output' ] )
			->getMock();
		if ( $verboseEnabled ) {
			$objectUnderTest->setOption( 'verbose', 1 );
		}
		// Create a mock IMetric instance that returns a fake result for ::calculate and ::getStatsdKey
		$mockIMetric = $this->createMock( IMetric::class );
		$mockIMetric->method( 'calculate' )
			->willReturn( 123 );
		$mockIMetric->method( 'getStatsdKey' )
			->willReturn( 'Mocked.StatsD.Key' );
		// Mock the MediaModerationMetricsFactory::newMetric method to return the mocked IMetric instance
		$mockMediaModerationMetricsFactory = $this->createMock( MediaModerationMetricsFactory::class );
		$mockMediaModerationMetricsFactory->method( 'newMetric' )
			->willReturn( $mockIMetric );
		// Create a mocked StatsdDataFactoryInterface
		$mockStatsdDataFactoryInterface = $this->createMock( StatsdDataFactoryInterface::class );
		$mockStatsdDataFactoryInterface->method( 'gauge' )
			->with( 'Mocked.StatsD.Key', 123 );
		if ( $verboseEnabled ) {
			// If verbose mode is enabled, then expect ::output is called.
			$objectUnderTest->expects( $this->exactly( count( MediaModerationMetricsFactory::METRICS ) ) )
				->method( 'output' );
		} else {
			$objectUnderTest->expects( $this->never() )
				->method( 'output' );
		}
		// Add the mocked services to the object under test
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$objectUnderTest->mediaModerationMetricsFactory = $mockMediaModerationMetricsFactory;
		$objectUnderTest->statsDataFactory = $mockStatsdDataFactoryInterface;
		// Call the method under test
		$objectUnderTest->execute();
	}

	public static function provideVerboseEnabled() {
		return [
			'Verbose mode enabled' => [ true ],
			'Verbose mode disabled' => [ false ],
		];
	}

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
