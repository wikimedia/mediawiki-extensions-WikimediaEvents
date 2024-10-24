<?php

namespace WikimediaEvents\Tests\Integration\PeriodicMetrics;

use MediaWiki\CheckUser\Logging\TemporaryAccountLoggerFactory;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikimediaEvents\PeriodicMetrics\ActiveTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

/**
 * @group Database
 * @covers \WikimediaEvents\PeriodicMetrics\ActiveTemporaryAccountIPViewersMetric
 */
class ActiveTemporaryAccountIPViewersMetricTest extends MediaWikiIntegrationTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
		ConvertibleTimestamp::setFakeTime( '20240506070809' );
	}

	private function getObjectUnderTest() {
		/** @var WikimediaEventsMetricsFactory $metricsFactory */
		$metricsFactory = $this->getServiceContainer()->get( 'WikimediaEventsMetricsFactory' );
		return $metricsFactory->newMetric( ActiveTemporaryAccountIPViewersMetric::class );
	}

	public function testCalculate() {
		// Check that the count is as expected. The test data is added in ::addDBDataOnce.
		$this->assertSame( 2, $this->getObjectUnderTest()->calculate() );
	}

	public function testGetName() {
		$this->assertSame(
			'active_temporary_account_ip_viewers_total',
			$this->getObjectUnderTest()->getName()
		);
	}

	public function addDBDataOnce() {
		// We can only add test data if the CheckUser extension is loaded.
		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
		// Use the CheckUser TemporaryAccountLogger to insert some testing logging table rows.
		/** @var TemporaryAccountLoggerFactory $checkUserTemporaryAccountLoggerFactory */
		$checkUserTemporaryAccountLoggerFactory = $this->getServiceContainer()
			->get( 'CheckUserTemporaryAccountLoggerFactory' );
		$checkUserTemporaryAccountLogger = $checkUserTemporaryAccountLoggerFactory->getLogger( 1 );
		// Add some entries which should be included in the calculation of the metric, with one user having more
		// than one reveal entry within the last 7 days.
		$firstTestUser = $this->getMutableTestUser()->getUserIdentity();
		$secondTestUser = $this->getMutableTestUser()->getUserIdentity();
		$checkUserTemporaryAccountLogger->logViewTemporaryAccountsOnIP( $firstTestUser, '1.2.3.4', '20240506000000' );
		$checkUserTemporaryAccountLogger->logViewIPs( $firstTestUser, '~2024-01', '20240503000000' );
		$checkUserTemporaryAccountLogger->logViewIPs( $secondTestUser, '~2024-02', '20240505000000' );
		// Add some entries which should be ignored because they are too old to be considered.
		$checkUserTemporaryAccountLogger->logViewTemporaryAccountsOnIP(
			$this->getMutableTestUser()->getUserIdentity(), '1.2.3.4', '20240101010101'
		);
		$checkUserTemporaryAccountLogger->logViewTemporaryAccountsOnIP(
			$this->getMutableTestUser()->getUserIdentity(), '1.2.3.4', '20240406000000'
		);
		$checkUserTemporaryAccountLogger->logViewIPs( $firstTestUser, '~2024-03', '20240405000000' );
		// Add some entries which should be ignored for this metric because they are for enabling and disabling access.
		$checkUserTemporaryAccountLogger->logAccessEnabled( $this->getMutableTestUser()->getUserIdentity() );
		$checkUserTemporaryAccountLogger->logAccessDisabled( $this->getMutableTestUser()->getUserIdentity() );
		$checkUserTemporaryAccountLogger->logAccessEnabled( $firstTestUser );
	}
}
