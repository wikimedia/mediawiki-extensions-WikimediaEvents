<?php

namespace WikimediaEvents\Tests\Integration\PeriodicMetrics;

use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\PeriodicMetrics\LocalTemporaryAccountIPViewersMetric;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

/**
 * @group Database
 * @covers \WikimediaEvents\PeriodicMetrics\LocalTemporaryAccountIPViewersMetric
 */
class LocalTemporaryAccountIPViewersMetricTest extends MediaWikiIntegrationTestCase {

	private function getObjectUnderTest() {
		/** @var WikimediaEventsMetricsFactory $metricsFactory */
		$metricsFactory = $this->getServiceContainer()->get( 'WikimediaEventsMetricsFactory' );
		return $metricsFactory->newMetric( LocalTemporaryAccountIPViewersMetric::class );
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
				// Add a first testing user into a groups which grants auto-enrolled access and non auto-enrolled access
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
		$this->assertSame( 3, $this->getObjectUnderTest()->calculate() );
	}

	public function testCalculateWhenNoRelevantGroups() {
		// Set wgGroupPermissions to have no group with the rights needed to IP reveal.
		$this->overrideConfigValue( MainConfigNames::GroupPermissions, [ 'user' => [ 'read' ] ] );
		$this->assertSame( 0, $this->getObjectUnderTest()->calculate() );
	}

	public function testGetName() {
		$this->assertSame(
			'local_temporary_account_ip_viewers_total',
			$this->getObjectUnderTest()->getName()
		);
	}
}
