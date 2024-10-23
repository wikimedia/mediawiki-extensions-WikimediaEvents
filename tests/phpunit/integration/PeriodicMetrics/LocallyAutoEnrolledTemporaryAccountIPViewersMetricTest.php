<?php

namespace WikimediaEvents\Tests\Integration\PeriodicMetrics;

use MediaWikiIntegrationTestCase;
use WikimediaEvents\PeriodicMetrics\LocallyAutoEnrolledTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

/**
 * @group Database
 * @covers \WikimediaEvents\PeriodicMetrics\LocallyAutoEnrolledTemporaryAccountIPViewersMetric
 */
class LocallyAutoEnrolledTemporaryAccountIPViewersMetricTest extends MediaWikiIntegrationTestCase {

	private function getObjectUnderTest() {
		/** @var WikimediaEventsMetricsFactory $metricsFactory */
		$metricsFactory = $this->getServiceContainer()->get( 'WikimediaEventsMetricsFactory' );
		return $metricsFactory->newMetric( LocallyAutoEnrolledTemporaryAccountIPViewersMetric::class );
	}

	public function testCalculate() {
		// Set some fake permission levels for the test
		$this->setGroupPermissions( [
			'checkuser' => [ 'checkuser-temporary-account-no-preference' => true ],
			'suppress' => [ 'checkuser-temporary-account-no-preference' => true ],
			'extendedconfirmed' => [ 'checkuser-temporary-account' => true ],
			'autoconfirmed' => [ 'checkuser-temporary-account-no-preference' => false ],
		] );
		// Add some test data to the user_groups table. This is more efficient than creating users which have the
		// groups.
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'user_groups' )
			->rows( [
				// Add a first testing user into a group which grants auto-enrolled access and non auto-enrolled access
				[ 'ug_user' => 1, 'ug_group' => 'checkuser' ],
				[ 'ug_user' => 1, 'ug_group' => 'suppress' ],
				[ 'ug_user' => 1, 'ug_group' => 'extendedconfirmed' ],
				// Add a second testing user into just a group which grants auto-enrolled access
				[ 'ug_user' => 2, 'ug_group' => 'suppress' ],
				// Add a third testing user which has non auto-enrolled access
				[ 'ug_user' => 3, 'ug_group' => 'extendedconfirmed' ],
				// Add a fourth testing user which has no access
				[ 'ug_user' => 4, 'ug_group' => 'autoconfirmed' ],
			] )
			->caller( __METHOD__ )
			->execute();
		// Check that the count is as expected
		$this->assertSame( 2, $this->getObjectUnderTest()->calculate() );
	}

	public function testGetName() {
		$this->assertSame(
			'locally_auto_enrolled_temporary_account_ip_viewers_total',
			$this->getObjectUnderTest()->getName()
		);
	}
}
