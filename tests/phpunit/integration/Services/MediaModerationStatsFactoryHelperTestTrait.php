<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Services;

use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Stats\Metrics\CounterMetric;
use Wikimedia\Stats\Metrics\TimingMetric;

/**
 * Provides methods which are used to assert on the whether metrics have been incremented / observed.
 */
trait MediaModerationStatsFactoryHelperTestTrait {
	/**
	 * Convenience function to assert that a per-wiki MediaModeration timing was called to observe a time.
	 *
	 * @param string $metric
	 * @param string[] $expectedLabels Optional list of additional expected label values.
	 * @return void
	 */
	private function assertTimingObserved( string $metric, array $expectedLabels = [] ): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'MediaModeration' )
			->getTiming( $metric );

		$samples = $metric->getSamples();

		$this->assertInstanceOf( TimingMetric::class, $metric );
		$this->assertSame( 1, $metric->getSampleCount() );

		$wikiId = WikiMap::getCurrentWikiId();
		$expectedLabels = array_merge(
			[ 'wiki' => rtrim( strtr( $wikiId, [ '-' => '_' ] ), '_' ) ],
			$expectedLabels
		);

		$actualLabels = array_combine( $metric->getLabelKeys(), $samples[0]->getLabelValues() );
		$this->assertArrayEquals( $expectedLabels, $actualLabels, false, true );
	}

	/**
	 * Convenience function to assert that a per-wiki MediaModeration counter was incremented exactly once.
	 *
	 * @param string $metricName
	 * @param string[] $expectedLabels Optional list of additional expected label values.
	 * @return void
	 */
	private function assertCounterIncremented( string $metricName, array $expectedLabels = [] ): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'MediaModeration' )
			->getCounter( $metricName );

		$samples = $metric->getSamples();

		$this->assertInstanceOf( CounterMetric::class, $metric );
		$this->assertSame( 1, $metric->getSampleCount() );
		$this->assertSame( 1.0, $samples[0]->getValue() );

		$wikiId = WikiMap::getCurrentWikiId();
		$expectedLabels = array_merge(
			[ 'wiki' => rtrim( strtr( $wikiId, [ '-' => '_' ] ), '_' ) ],
			$expectedLabels
		);

		$actualLabels = array_combine( $metric->getLabelKeys(), $samples[0]->getLabelValues() );
		$this->assertArrayEquals( $expectedLabels, $actualLabels, false, true );
	}

	/**
	 * Convenience function to assert that the IPReputation metric counter was not incremented.
	 *
	 * @param string $metricName
	 * @return void
	 */
	private function assertCounterNotIncremented( string $metricName ): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'MediaModeration' )
			->getCounter( $metricName );

		$this->assertInstanceOf( CounterMetric::class, $metric );
		$this->assertSame( 0, $metric->getSampleCount() );
	}
}
