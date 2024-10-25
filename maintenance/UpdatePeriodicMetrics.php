<?php

namespace WikimediaEvents\Maintenance;

use InvalidArgumentException;
use Maintenance;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\Stats\StatsFactory;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Generates snapshots for several metrics so that they can be pulled by Prometheus.
 * Copied, with modification, from MediaModeration maintenance/updateMetrics.php
 */
class UpdatePeriodicMetrics extends Maintenance {

	private StatsFactory $statsFactory;
	private WikimediaEventsMetricsFactory $wikimediaEventsMetricsFactory;
	private LoggerInterface $logger;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'WikimediaEvents' );

		$this->addDescription( 'Generates a snapshot of several metrics which can then be pulled by Prometheus.' );
		$this->addOption( 'verbose', 'Output values of metrics calculated. Default is to not output.' );
		$this->addOption(
			'global-metrics',
			'Generates metrics which are global (i.e. not per-wiki). ' .
			'Without specifying this option the script will only generate per-wiki metrics.'
		);
	}

	/**
	 * Initialise dependencies (services and logger).
	 */
	private function initServices(): void {
		$this->statsFactory = $this->getServiceContainer()->getStatsFactory();
		$this->wikimediaEventsMetricsFactory = $this->getServiceContainer()->get( 'WikimediaEventsMetricsFactory' );
		$this->logger = LoggerFactory::getInstance( 'WikimediaEvents' );
	}

	/** @inheritDoc */
	public function execute() {
		$this->initServices();

		if ( $this->hasOption( 'global-metrics' ) ) {
			$metrics = $this->wikimediaEventsMetricsFactory->getAllGlobalMetrics();
		} else {
			$metrics = $this->wikimediaEventsMetricsFactory->getAllPerWikiMetrics();
		}

		foreach ( $metrics as $metricName ) {
			try {
				$metric = $this->wikimediaEventsMetricsFactory->newMetric( $metricName );
			} catch ( InvalidArgumentException $_ ) {
				$this->error( 'ERROR: Metric "' . $metricName . '" failed to be constructed' );
				$this->logger->error(
					'Metric {metric_name} failed to be constructed.', [ 'metric_name' => $metricName ]
				);
				continue;
			}

			$gaugeMetric = $this->statsFactory
				->withComponent( 'WikimediaEvents' )
				->getGauge( $metric->getName() );

			foreach ( $metric->getLabels() as $key => $value ) {
				$gaugeMetric->setLabel( $key, $value );
			}

			$metricValue = $metric->calculate();
			$gaugeMetric->set( $metricValue );

			if ( $this->hasOption( 'verbose' ) ) {
				$outputString = $metric->getName();
				if ( count( $metric->getLabels() ) ) {
					$outputString .= ' with label(s) ' . implode( ',', $metric->getLabels() );
				}
				$outputString .= ' is ' . $metricValue . '.' . PHP_EOL;
				$this->output( $outputString );
			}
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = UpdatePeriodicMetrics::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
