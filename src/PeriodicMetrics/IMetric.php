<?php

namespace MediaWiki\Extension\MediaModeration\PeriodicMetrics;

/**
 * Represents a metric that can be calculated at any time.
 * Copied from GrowthExperiments includes/PeriodicMetrics/IMetric.php
 */
interface IMetric {
	/**
	 * Calculate the value of the metric
	 *
	 * @return int
	 */
	public function calculate(): int;

	/**
	 * Get statsd key where the metric should be stored
	 *
	 * This is a per-wiki key.
	 *
	 * @return string
	 */
	public function getStatsdKey(): string;
}
