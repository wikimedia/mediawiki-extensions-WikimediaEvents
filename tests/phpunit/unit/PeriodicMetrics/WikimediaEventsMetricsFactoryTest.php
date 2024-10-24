<?php

namespace WikimediaEvents\Tests\Unit\PeriodicMetrics;

use InvalidArgumentException;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;
use WikimediaEvents\PeriodicMetrics\ActiveTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\LocallyAutoEnrolledTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\LocalTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

/**
 * @covers \WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory
 */
class WikimediaEventsMetricsFactoryTest extends MediaWikiUnitTestCase {

	use MockServiceDependenciesTrait;

	public static function validMetricClasses() {
		return [
			LocallyAutoEnrolledTemporaryAccountIPViewersMetric::class,
			LocalTemporaryAccountIPViewersMetric::class,
			ActiveTemporaryAccountIPViewersMetric::class,
		];
	}

	public function testNewMetricOnInvalidMetric() {
		$this->expectException( InvalidArgumentException::class );
		/** @var WikimediaEventsMetricsFactory $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( WikimediaEventsMetricsFactory::class, [] );
		$objectUnderTest->newMetric( 'testing' );
	}

	/** @dataProvider provideMetricClasses */
	public function testNewMetric( $className ) {
		/** @var WikimediaEventsMetricsFactory $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( WikimediaEventsMetricsFactory::class, [] );
		// Call the method under test
		$this->assertInstanceOf(
			$className,
			$objectUnderTest->newMetric( $className ),
			'::newMetric returned an object where the class name was not as expected.'
		);
	}

	public static function provideMetricClasses() {
		foreach ( self::validMetricClasses() as $class ) {
			yield $class => [ $class ];
		}
	}

	public function testGetMetrics() {
		/** @var WikimediaEventsMetricsFactory $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance( WikimediaEventsMetricsFactory::class, [] );
		$this->assertSame( self::validMetricClasses(), $objectUnderTest->getAllMetrics() );
	}
}
