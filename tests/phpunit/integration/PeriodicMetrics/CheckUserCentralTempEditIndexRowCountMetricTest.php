<?php

namespace WikimediaEvents\Tests\Integration\PeriodicMetrics;

use MediaWikiIntegrationTestCase;
use WikimediaEvents\PeriodicMetrics\CheckUserCentralTempEditIndexRowCountMetric;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

/**
 * @group Database
 * @covers \WikimediaEvents\PeriodicMetrics\CheckUserCentralTempEditIndexRowCountMetric
 */
class CheckUserCentralTempEditIndexRowCountMetricTest extends MediaWikiIntegrationTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
	}

	private function getObjectUnderTest() {
		/** @var WikimediaEventsMetricsFactory $metricsFactory */
		$metricsFactory = $this->getServiceContainer()->get( 'WikimediaEventsMetricsFactory' );
		return $metricsFactory->newMetric( CheckUserCentralTempEditIndexRowCountMetric::class );
	}

	public function testCalculateWhenTableEmpty() {
		$this->assertSame( 0, $this->getObjectUnderTest()->calculate() );
	}

	public function testCalculateWhenTableHasRows() {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'cuci_temp_edit' )
			->row( [
				// 127.0.0.1
				'cite_ip_hex' => '7F000001',
				'cite_ciwm_id' => 2,
				'cite_timestamp' => $this->getDb()->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
		$this->assertSame( 1, $this->getObjectUnderTest()->calculate() );
	}

	public function testGetName() {
		$this->assertSame(
			'checkuser_central_index_row_count_total',
			$this->getObjectUnderTest()->getName()
		);
	}

	public function testGetLabels() {
		$this->assertSame( [ 'table' => 'cuci_temp_edit' ], $this->getObjectUnderTest()->getLabels() );
	}
}
