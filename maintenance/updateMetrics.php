<?php

namespace MediaWiki\Extension\MediaModeration\Maintenance;

use InvalidArgumentException;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use Maintenance;
use MediaWiki\Extension\MediaModeration\PeriodicMetrics\MediaModerationMetricsFactory;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Pushes information about the status of scanning to statsd.
 * Copied, with modification, from GrowthExperiments maintenance/updateMetrics.php
 */
class UpdateMetrics extends Maintenance {

	private StatsdDataFactoryInterface $statsDataFactory;
	private MediaModerationMetricsFactory $mediaModerationMetricsFactory;
	private LoggerInterface $logger;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'MediaModeration' );

		$this->addDescription( 'Push calculated metrics to StatsD' );
		$this->addOption( 'verbose', 'Output values of metrics calculated. Default is to not output.' );
	}

	/**
	 * Initialise dependencies (services and logger).
	 * This method is protected to allow mocking.
	 */
	protected function initServices(): void {
		$this->statsDataFactory = $this->getServiceContainer()->getPerDbNameStatsdDataFactory();
		$this->mediaModerationMetricsFactory = $this->getServiceContainer()->get( 'MediaModerationMetricsFactory' );
		$this->logger = LoggerFactory::getInstance( 'mediamoderation' );
	}

	/** @inheritDoc */
	public function execute() {
		$this->initServices();

		foreach ( MediaModerationMetricsFactory::METRICS as $metricName ) {
			try {
				$metric = $this->mediaModerationMetricsFactory->newMetric( $metricName );
			} catch ( InvalidArgumentException $_ ) {
				$this->error( 'ERROR: Metric "' . $metricName . '" failed to be constructed' );
				$this->logger->error(
					'Metric {metric_name} failed to be constructed.', [ 'metric_name' => $metricName ]
				);
				continue;
			}

			$metricValue = $metric->calculate();
			$this->statsDataFactory->gauge(
				$metric->getStatsdKey(),
				$metricValue
			);

			if ( $this->hasOption( 'verbose' ) ) {
				$this->output( $metric->getStatsdKey() . ' is ' . $metricValue . '.' . PHP_EOL );
			}
		}
	}
}

$maintClass = UpdateMetrics::class;
require_once RUN_MAINTENANCE_IF_MAIN;
