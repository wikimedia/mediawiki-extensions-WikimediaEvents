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

	public function testExecuteForFailedConstructionOfMetric() {
		// Mock that ::getAllMetrics returns an invalid metric.
		$mockWikimediaEventsMetricsFactory = $this->createMock( WikimediaEventsMetricsFactory::class );
		$mockWikimediaEventsMetricsFactory->method( 'getAllMetrics' )
			->willReturn( [ 'invalidmetric' ] );
		$mockWikimediaEventsMetricsFactory->method( 'newMetric' )
			->willThrowException( new InvalidArgumentException() );
		$this->setService( 'WikimediaEventsMetricsFactory', $mockWikimediaEventsMetricsFactory );
		$this->setService( 'StatsFactory', $this->createNoOpMock( StatsFactory::class ) );
		// Run the maintenance script
		$this->maintenance->execute();
		$this->expectOutputRegex( '/Metric "invalidmetric" failed to be constructed/' );
	}

	/** @dataProvider provideIsVerbose */
	public function testExecuteForMockIMetric( $isVerboseMode ) {
		// Define a mock IMetric instance which will be returned by a mock WikimediaEventsMetricsFactory::newMetric
		$mockIMetric = $this->createMock( IMetric::class );
		$mockIMetric->method( 'getName' )
			->willReturn( 'mock_metric_name' );
		$mockIMetric->method( 'getLabels' )
			->willReturn( [ 'wiki' => 'test' ] );
		$mockIMetric->method( 'calculate' )
			->willReturn( 1234 );
		$mockWikimediaEventsMetricsFactory = $this->createMock( WikimediaEventsMetricsFactory::class );
		$mockWikimediaEventsMetricsFactory->method( 'getAllMetrics' )
			->willReturn( [ 'MockIMetric' ] );
		$mockWikimediaEventsMetricsFactory->method( 'newMetric' )
			->with( 'MockIMetric' )
			->willReturn( $mockIMetric );
		$this->setService( 'WikimediaEventsMetricsFactory', $mockWikimediaEventsMetricsFactory );

		// Execute the maintenance script
		if ( $isVerboseMode ) {
			$this->maintenance->setOption( 'verbose', 1 );
			$this->expectOutputString( "mock_metric_name with label(s) test is 1234.\n" );
		} else {
			$this->expectOutputString( '' );
		}
		$this->maintenance->execute();

		// Check that the gauge metric with the name 'mock_metric_name' has been set to 1234.
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'WikimediaEvents' )
			->getGauge( 'mock_metric_name' );
		$samples = $metric->getSamples();
		$this->assertInstanceOf( GaugeMetric::class, $metric );
		$this->assertSame( 1, $metric->getSampleCount() );
		$this->assertSame( 1234.0, $samples[0]->getValue() );
		$this->assertArrayEquals( [ 'wiki' => 'test' ], $samples[0]->getLabelValues(), true );
	}

	public static function provideIsVerbose() {
		return [
			'Not in verbose mode' => [ true ],
			'In verbose mode' => [ false ],
		];
	}
}
