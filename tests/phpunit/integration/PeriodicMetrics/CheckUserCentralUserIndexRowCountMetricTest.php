<?php

namespace WikimediaEvents\Tests\Integration\PeriodicMetrics;

use MediaWikiIntegrationTestCase;
use WikimediaEvents\PeriodicMetrics\CheckUserCentralUserIndexRowCountMetric;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

/**
 * @group Database
 * @covers \WikimediaEvents\PeriodicMetrics\CheckUserCentralUserIndexRowCountMetric
 */
class CheckUserCentralUserIndexRowCountMetricTest extends MediaWikiIntegrationTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
	}

	private function getObjectUnderTest() {
		/** @var WikimediaEventsMetricsFactory $metricsFactory */
		$metricsFactory = $this->getServiceContainer()->get( 'WikimediaEventsMetricsFactory' );
		return $metricsFactory->newMetric( CheckUserCentralUserIndexRowCountMetric::class );
	}

	public function testCalculateWhenTableEmpty() {
		$this->assertSame( 0, $this->getObjectUnderTest()->calculate() );
	}

	public function testCalculateWhenTableHasRows() {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'cuci_user' )
			->rows( [
				[
					'ciu_timestamp' => $this->getDb()->timestamp( '20200304050607' ),
					'ciu_ciwm_id' => 1,
					'ciu_central_id' => 2,
				],
				[
					'ciu_timestamp' => $this->getDb()->timestamp( '20200304050607' ),
					'ciu_ciwm_id' => 2,
					'ciu_central_id' => 2,
				]
			] )
			->caller( __METHOD__ )
			->execute();
		$this->assertSame( 2, $this->getObjectUnderTest()->calculate() );
	}

	public function testGetName() {
		$this->assertSame(
			'checkuser_central_index_row_count_total',
			$this->getObjectUnderTest()->getName()
		);
	}

	public function testGetLabels() {
		$this->assertSame( [ 'table' => 'cuci_user' ], $this->getObjectUnderTest()->getLabels() );
	}
}
