<?php

namespace WikimediaEvents\Tests\Integration\PeriodicMetrics;

use MediaWikiIntegrationTestCase;
use WikimediaEvents\PeriodicMetrics\GlobalTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

/**
 * @group Database
 * @covers \WikimediaEvents\PeriodicMetrics\GlobalTemporaryAccountIPViewersMetric
 */
class GlobalTemporaryAccountIPViewersMetricTest extends MediaWikiIntegrationTestCase {

	public function setUp(): void {
		parent::setUp();
		// The code being tested relies on CentralAuth services, and this class inserts to tables only added when
		// CentralAuth is installed.
		$this->markTestSkippedIfExtensionNotLoaded( 'CentralAuth' );
	}

	private function getObjectUnderTest() {
		/** @var WikimediaEventsMetricsFactory $metricsFactory */
		$metricsFactory = $this->getServiceContainer()->get( 'WikimediaEventsMetricsFactory' );
		return $metricsFactory->newMetric( GlobalTemporaryAccountIPViewersMetric::class );
	}

	public function testCalculate() {
		// Set some fake permissions for global groups for the test
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_group_permissions' )
			->rows( [
				[ 'ggp_group' => 'steward', 'ggp_permission' => 'checkuser-temporary-account-no-preference' ],
				[ 'ggp_group' => 'global-sysop', 'ggp_permission' => 'checkuser-temporary-account-no-preference' ],
				[ 'ggp_group' => 'global-rollbacker', 'ggp_permission' => 'checkuser-temporary-account' ],
			] )
			->caller( __METHOD__ )
			->execute();
		// Add some test data to the user_groups table. This is more efficient than creating users which have the
		// groups.
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_user_groups' )
			->rows( [
				// Add a first testing user into a global group which grants auto-enrolled access and non
				// auto-enrolled access
				[ 'gug_user' => 1, 'gug_group' => 'steward' ],
				[ 'gug_user' => 1, 'gug_group' => 'global-sysop' ],
				[ 'gug_user' => 1, 'gug_group' => 'global-rollbacker' ],
				// Add a second testing user into just a global group which grants auto-enrolled access
				[ 'gug_user' => 2, 'gug_group' => 'steward' ],
				// Add a third testing user which has non auto-enrolled access
				[ 'gug_user' => 3, 'gug_group' => 'global-rollbacker' ],
				// Add a fourth testing user which has no access
				[ 'gug_user' => 4, 'gug_group' => 'vrt-permissions' ],
			] )
			->caller( __METHOD__ )
			->execute();
		// Check that the count is as expected
		$this->assertSame( 3, $this->getObjectUnderTest()->calculate() );
	}

	public function testCalculateWhenNoGlobalGroups() {
		$this->assertSame( 0, $this->getObjectUnderTest()->calculate() );
	}

	public function testGetName() {
		$this->assertSame(
			'global_temporary_account_ip_viewers_total',
			$this->getObjectUnderTest()->getName()
		);
	}

	public function testGetLabels() {
		$this->assertCount( 0, $this->getObjectUnderTest()->getLabels() );
	}
}
