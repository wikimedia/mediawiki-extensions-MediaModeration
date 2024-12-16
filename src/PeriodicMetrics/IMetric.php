<?php

namespace MediaWiki\Extension\MediaModeration\PeriodicMetrics;

/**
 * Represents a metric that can be calculated at any time.
 * Copied, with modification, from GrowthExperiments includes/PeriodicMetrics/IMetric.php
 */
interface IMetric {
	/**
	 * Calculate the value of the metric.
	 *
	 * @return int
	 */
	public function calculate(): int;

	/**
	 * Gets the name of the StatsD key for the metric. Used to
	 * copy the Prometheus data to StatsD to assist in migration
	 * from StatsD to Prometheus.
	 *
	 * @return string
	 */
	public function getStatsdKey(): string;

	/**
	 * Gets the name of the metric.
	 *
	 * @return string
	 */
	public function getName(): string;
}
