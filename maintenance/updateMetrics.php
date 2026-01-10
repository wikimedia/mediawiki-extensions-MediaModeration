<?php

namespace MediaWiki\Extension\MediaModeration\Maintenance;

use InvalidArgumentException;
use MediaWiki\Extension\MediaModeration\PeriodicMetrics\MediaModerationMetricsFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\WikiMap\WikiMap;
use Psr\Log\LoggerInterface;
use Wikimedia\Stats\StatsFactory;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Pushes information about the status of scanning to prometheus.
 * Copied, with modification, from GrowthExperiments maintenance/updateMetrics.php
 */
class UpdateMetrics extends Maintenance {

	private StatsFactory $statsFactory;
	private MediaModerationMetricsFactory $mediaModerationMetricsFactory;
	private LoggerInterface $logger;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'MediaModeration' );

		$this->addDescription( 'Push calculated metrics to Prometheus' );
		$this->addOption( 'verbose', 'Output values of metrics calculated. Default is to not output.' );
	}

	/**
	 * Initialise dependencies (services and logger).
	 * This method is protected to allow mocking.
	 */
	protected function initServices(): void {
		$this->statsFactory = $this->getServiceContainer()->getStatsFactory();
		$this->mediaModerationMetricsFactory = $this->getServiceContainer()->get( 'MediaModerationMetricsFactory' );
		$this->logger = LoggerFactory::getInstance( 'mediamoderation' );
	}

	/** @inheritDoc */
	public function execute() {
		$this->initServices();
		$wiki = WikiMap::getCurrentWikiId();

		foreach ( MediaModerationMetricsFactory::METRICS as $metricName ) {
			try {
				$metric = $this->mediaModerationMetricsFactory->newMetric( $metricName );
			} catch ( InvalidArgumentException ) {
				$this->error( 'ERROR: Metric "' . $metricName . '" failed to be constructed' );
				$this->logger->error(
					'Metric {metric_name} failed to be constructed.', [ 'metric_name' => $metricName ]
				);
				continue;
			}

			$metricValue = $metric->calculate();
			$this->statsFactory->withComponent( 'MediaModeration' )
				->getGauge( $metric->getName() )
				->setLabel( 'wiki', $wiki )
				->copyToStatsdAt( "$wiki." . $metric->getStatsdKey() )
				->set( $metricValue );

			if ( $this->hasOption( 'verbose' ) ) {
				$this->output( $metric->getName() . ' is ' . $metricValue . '.' . PHP_EOL );
			}
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = UpdateMetrics::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
