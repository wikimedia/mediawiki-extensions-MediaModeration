<?php

namespace MediaWiki\Extension\MediaModeration\PeriodicMetrics;

use InvalidArgumentException;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Allows the construction of IMetric classes.
 * Copy, with some modification, from GrowthExperiments
 * includes/PeriodicMetrics/MediaModerationMetricsFactory.php
 */
class MediaModerationMetricsFactory {

	private IReadableDatabase $dbr;

	/** @var string[] */
	public const METRICS = [
		TotalTableCountMetric::class,
		UnscannedImagesMetric::class,
		UnscannedImagesWithLastCheckedDefinedMetric::class,
	];

	/**
	 * @param IReadableDatabase $dbr
	 */
	public function __construct( IReadableDatabase $dbr ) {
		$this->dbr = $dbr;
	}

	/**
	 * Returns an instance of the class that extends IMetric given
	 * in $className.
	 *
	 * @param string $className
	 * @return IMetric
	 * @throws InvalidArgumentException if metric class name is not supported
	 */
	public function newMetric( string $className ): IMetric {
		switch ( $className ) {
			case UnscannedImagesMetric::class:
				return new UnscannedImagesMetric( $this->dbr );
			case TotalTableCountMetric::class:
				return new TotalTableCountMetric( $this->dbr );
			case UnscannedImagesWithLastCheckedDefinedMetric::class:
				return new UnscannedImagesWithLastCheckedDefinedMetric( $this->dbr );
			default:
				throw new InvalidArgumentException( 'Unsupported metric class name' );
		}
	}
}
