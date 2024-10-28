<?php

namespace WikimediaEvents\Tests\Integration\PeriodicMetrics;

use MediaWikiIntegrationTestCase;
use WikimediaEvents\PeriodicMetrics\GlobalTemporaryAccountIPViewersWithEnabledPreferenceMetric;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

/**
 * @group Database
 * @covers \WikimediaEvents\PeriodicMetrics\GlobalTemporaryAccountIPViewersWithEnabledPreferenceMetric
 */
class GlobalTemporaryAccountIPViewersWithEnabledPreferenceMetricTest extends MediaWikiIntegrationTestCase {

	public function setUp(): void {
		parent::setUp();
		// The code being tested relies on CentralAuth and GlobalPreferences services, and this class inserts
		// to tables only added when CentralAuth and GlobalPreferences is installed.
		$this->markTestSkippedIfExtensionNotLoaded( 'CentralAuth' );
		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalPreferences' );
	}

	private function getObjectUnderTest() {
		/** @var WikimediaEventsMetricsFactory $metricsFactory */
		$metricsFactory = $this->getServiceContainer()->get( 'WikimediaEventsMetricsFactory' );
		return $metricsFactory->newMetric( GlobalTemporaryAccountIPViewersWithEnabledPreferenceMetric::class );
	}

	public function testCalculate() {
		// Set some fake permissions for global groups for the test
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_group_permissions' )
			->rows( [
				[ 'ggp_group' => 'steward', 'ggp_permission' => 'checkuser-temporary-account-no-preference' ],
				[ 'ggp_group' => 'steward', 'ggp_permission' => 'checkuser-temporary-account' ],
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
				[ 'gug_user' => 1, 'gug_group' => 'global-rollbacker' ],
				// Add a second testing user into just a global group which grants auto-enrolled access
				[ 'gug_user' => 2, 'gug_group' => 'steward' ],
				// Add testing users which have non auto-enrolled access
				[ 'gug_user' => 3, 'gug_group' => 'global-rollbacker' ],
				[ 'gug_user' => 4, 'gug_group' => 'global-rollbacker' ],
				[ 'gug_user' => 5, 'gug_group' => 'global-rollbacker' ],
				[ 'gug_user' => 6, 'gug_group' => 'global-rollbacker' ],
				// Add a 7th testing user which has no access
				[ 'gug_user' => 7, 'gug_group' => 'vrt-permissions' ],
			] )
			->caller( __METHOD__ )
			->execute();
		// Check the global preference for most of the test users, with some users having the value set to 0 or
		// no global preference row defined.
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->rows( [
				// Check the preference for users which either don't need to check it or don't have the right.
				[ 'gp_user' => 1, 'gp_property' => 'checkuser-temporary-account-enable', 'gp_value' => '1' ],
				[ 'gp_user' => 7, 'gp_property' => 'checkuser-temporary-account-enable', 'gp_value' => '1' ],
				// Enable the preference for two users that need to enable it to use the tool. This is the
				// rows that we expect the metric will count.
				[ 'gp_user' => 3, 'gp_property' => 'checkuser-temporary-account-enable', 'gp_value' => '1' ],
				[ 'gp_user' => 6, 'gp_property' => 'checkuser-temporary-account-enable', 'gp_value' => '1' ],
				// Disable the preference (value as 0) for a user which needs to enable it to use the tool.
				[ 'gp_user' => 4, 'gp_property' => 'checkuser-temporary-account-enable', 'gp_value' => '0' ],
			] )
			->caller( __METHOD__ )
			->execute();
		// Check that the count is as expected
		$this->assertSame( 2, $this->getObjectUnderTest()->calculate() );
	}

	public function testCalculateWhenAllUsersHaveAutoEnrolledAccess() {
		// Set some fake permissions for global groups for the test
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_group_permissions' )
			->rows( [
				[ 'ggp_group' => 'steward', 'ggp_permission' => 'checkuser-temporary-account-no-preference' ],
				[ 'ggp_group' => 'global-rollbacker', 'ggp_permission' => 'checkuser-temporary-account' ],
			] )
			->caller( __METHOD__ )
			->execute();
		// Add some test data to the user_groups table. This is more efficient than creating users which have the
		// groups.
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_user_groups' )
			->rows( [
				// Only have one user which has auto-enrolled access through one group and non-auto enrolled access
				// through another group.
				[ 'gug_user' => 1, 'gug_group' => 'steward' ],
				[ 'gug_user' => 1, 'gug_group' => 'global-rollbacker' ],
			] )
			->caller( __METHOD__ )
			->execute();
		// Check the global preference for most of the test users, with some users having the value set to 0 or
		// no global preference row defined.
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->row( [ 'gp_user' => 1, 'gp_property' => 'checkuser-temporary-account-enable', 'gp_value' => '1' ] )
			->caller( __METHOD__ )
			->execute();
		// Check that the count is 0, as the user has auto-enrolled access.
		$this->assertSame( 0, $this->getObjectUnderTest()->calculate() );
	}

	public function testCalculateWhenNoGlobalGroups() {
		$this->assertSame( 0, $this->getObjectUnderTest()->calculate() );
	}

	public function testGetName() {
		$this->assertSame(
			'global_temporary_account_ip_viewers_with_enabled_preference_total',
			$this->getObjectUnderTest()->getName()
		);
	}

	public function testGetLabels() {
		$this->assertCount( 0, $this->getObjectUnderTest()->getLabels() );
	}
}
