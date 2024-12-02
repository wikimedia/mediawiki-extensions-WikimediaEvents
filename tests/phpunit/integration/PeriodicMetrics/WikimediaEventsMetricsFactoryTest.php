<?php

namespace WikimediaEvents\Tests\Unit\PeriodicMetrics;

use InvalidArgumentException;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\PeriodicMetrics\ActiveTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\GloballyAutoEnrolledTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\GlobalTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\LocallyAutoEnrolledTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\LocalTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

/**
 * @covers \WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory
 */
class WikimediaEventsMetricsFactoryTest extends MediaWikiIntegrationTestCase {

	use MockServiceDependenciesTrait;

	public static function validMetricClasses() {
		return [
			LocallyAutoEnrolledTemporaryAccountIPViewersMetric::class,
			LocalTemporaryAccountIPViewersMetric::class,
			ActiveTemporaryAccountIPViewersMetric::class,
		];
	}

	public static function validCentralAuthOnlyMetricClasses() {
		return [
			GloballyAutoEnrolledTemporaryAccountIPViewersMetric::class,
			GlobalTemporaryAccountIPViewersMetric::class,
		];
	}

	private function getObjectUnderTest(): WikimediaEventsMetricsFactory {
		return $this->getServiceContainer()->get( 'WikimediaEventsMetricsFactory' );
	}

	public function testNewMetricOnInvalidMetric() {
		$this->expectException( InvalidArgumentException::class );
		$this->getObjectUnderTest()->newMetric( 'testing' );
	}

	public function testNewMetricForCentralAuthOnlyMetricWhenCentralAuthNotInstalled() {
		$mockExtensionRegistry = $this->createMock( ExtensionRegistry::class );
		$mockExtensionRegistry->method( 'isLoaded' )
			->with( 'CentralAuth' )
			->willReturn( false );
		/** @var WikimediaEventsMetricsFactory $objectUnderTest */
		$objectUnderTest = $this->newServiceInstance(
			WikimediaEventsMetricsFactory::class,
			[ 'extensionRegistry' => $mockExtensionRegistry ]
		);
		// Check that the call results in an error, because the metric cannot be constructed if CentralAuth is not
		// installed.
		$this->expectException( InvalidArgumentException::class );
		$objectUnderTest->newMetric( GloballyAutoEnrolledTemporaryAccountIPViewersMetric::class );
	}

	/** @dataProvider provideMetricClasses */
	public function testNewMetric( $className ) {
		$this->assertInstanceOf(
			$className,
			$this->getObjectUnderTest()->newMetric( $className ),
			'::newMetric returned an object where the class name was not as expected.'
		);
	}

	public static function provideMetricClasses() {
		foreach ( self::validMetricClasses() as $class ) {
			yield $class => [ $class ];
		}
	}

	/** @dataProvider provideCentralAuthMetricClasses */
	public function testNewMetricForCentralAuthOnlyMetrics( $className ) {
		$this->markTestSkippedIfExtensionNotLoaded( 'CentralAuth' );
		$this->assertInstanceOf(
			$className,
			$this->getObjectUnderTest()->newMetric( $className ),
			'::newMetric returned an object where the class name was not as expected.'
		);
	}

	public static function provideCentralAuthMetricClasses() {
		foreach ( self::validCentralAuthOnlyMetricClasses() as $class ) {
			yield $class => [ $class ];
		}
	}

	public function testGetMetrics() {
		$this->assertSame(
			array_merge( self::validMetricClasses(), self::validCentralAuthOnlyMetricClasses() ),
			$this->getObjectUnderTest()->getAllMetrics()
		);
	}

	public function testGetPerWikiMetrics() {
		$this->assertSame( self::validMetricClasses(), $this->getObjectUnderTest()->getAllPerWikiMetrics() );
	}

	public function testGetGlobalMetrics() {
		$this->assertSame(
			self::validCentralAuthOnlyMetricClasses(),
			$this->getObjectUnderTest()->getAllGlobalMetrics()
		);
	}
}
