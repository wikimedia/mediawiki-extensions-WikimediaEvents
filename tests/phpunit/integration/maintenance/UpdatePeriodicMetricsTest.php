<?php

namespace WikimediaEvents\Tests\Integration\PeriodicMetrics;

use InvalidArgumentException;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Wikimedia\Stats\Metrics\GaugeMetric;
use Wikimedia\Stats\StatsFactory;
use WikimediaEvents\Maintenance\UpdatePeriodicMetrics;
use WikimediaEvents\PeriodicMetrics\IMetric;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

/**
 * @covers \WikimediaEvents\Maintenance\UpdatePeriodicMetrics
 */
class UpdatePeriodicMetricsTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass() {
		return UpdatePeriodicMetrics::class;
	}

	/** @dataProvider provideIsInGlobalMode */
	public function testExecuteForFailedConstructionOfMetric( $isInGlobalMode ) {
		// Mock that ::getAllMetrics returns an invalid metric.
		$mockWikimediaEventsMetricsFactory = $this->createMock( WikimediaEventsMetricsFactory::class );
		if ( $isInGlobalMode ) {
			$mockWikimediaEventsMetricsFactory->method( 'getAllGlobalMetrics' )
				->willReturn( [ 'invalidmetric' ] );
		} else {
			$mockWikimediaEventsMetricsFactory->method( 'getAllPerWikiMetrics' )
				->willReturn( [ 'invalidmetric' ] );
		}
		$mockWikimediaEventsMetricsFactory->expects( $this->once() )
			->method( 'newMetric' )
			->with( 'invalidmetric' )
			->willThrowException( new InvalidArgumentException() );
		$this->setService( 'WikimediaEventsMetricsFactory', $mockWikimediaEventsMetricsFactory );
		$this->setService( 'StatsFactory', $this->createNoOpMock( StatsFactory::class ) );
		// Run the maintenance script
		if ( $isInGlobalMode ) {
			$this->maintenance->setOption( 'global-metrics', 1 );
		}
		$this->maintenance->execute();
		$this->expectOutputRegex( '/Metric "invalidmetric" failed to be constructed/' );
	}

	public static function provideIsInGlobalMode() {
		return [
			'--global-metrics provided' => [ true ],
			'--global-metrics not provided' => [ false ],
		];
	}

	/** @dataProvider provideExecuteForMockIMetric */
	public function testExecuteForMockIMetric(
		$isVerboseMode, $metricLabels, $metricValue, $expectedOutputString
	) {
		// Define a mock IMetric instance which will be returned by a mock WikimediaEventsMetricsFactory::newMetric
		$mockIMetric = $this->createMock( IMetric::class );
		$mockIMetric->method( 'getName' )
			->willReturn( 'mock_metric_name' );
		$mockIMetric->method( 'getLabels' )
			->willReturn( $metricLabels );
		$mockIMetric->method( 'calculate' )
			->willReturn( $metricValue );
		$mockWikimediaEventsMetricsFactory = $this->createMock( WikimediaEventsMetricsFactory::class );
		$mockWikimediaEventsMetricsFactory->method( 'getAllPerWikiMetrics' )
			->willReturn( [ 'MockIMetric' ] );
		$mockWikimediaEventsMetricsFactory->method( 'newMetric' )
			->with( 'MockIMetric' )
			->willReturn( $mockIMetric );
		$this->setService( 'WikimediaEventsMetricsFactory', $mockWikimediaEventsMetricsFactory );

		// Execute the maintenance script
		if ( $isVerboseMode ) {
			$this->maintenance->setOption( 'verbose', 1 );
		}
		$this->expectOutputString( $expectedOutputString );
		$this->maintenance->execute();

		// Check that the gauge metric with the name 'mock_metric_name' has been set to 1234.
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'WikimediaEvents' )
			->getGauge( 'mock_metric_name' );
		$samples = $metric->getSamples();
		$this->assertInstanceOf( GaugeMetric::class, $metric );
		$this->assertSame( 1, $metric->getSampleCount() );
		$this->assertSame( floatval( $metricValue ), $samples[0]->getValue() );
		$this->assertArrayEquals( $metricLabels, $samples[0]->getLabelValues(), true );
	}

	public static function provideExecuteForMockIMetric() {
		return [
			'Not in verbose mode with labels' => [
				true, [ 'wiki' => 'test' ], 1234, "mock_metric_name with label(s) test is 1234.\n",
			],
			'In verbose mode with labels' => [ false, [ 'wiki' => 'test' ], 12345, "" ],
			'Not in verbose mode with no labels' => [ true, [], 123, "mock_metric_name is 123.\n" ],
			'In verbose mode with no labels' => [ false, [], 123567, "" ],
		];
	}
}
