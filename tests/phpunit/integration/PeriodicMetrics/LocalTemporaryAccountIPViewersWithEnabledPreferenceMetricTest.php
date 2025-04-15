<?php

namespace WikimediaEvents\Tests\Integration\PeriodicMetrics;

use GlobalPreferences\Storage;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use MediaWiki\User\UserOptionsManager;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\PeriodicMetrics\LocalTemporaryAccountIPViewersWithEnabledPreferenceMetric;
use WikimediaEvents\PeriodicMetrics\WikimediaEventsMetricsFactory;

/**
 * @group Database
 * @covers \WikimediaEvents\PeriodicMetrics\LocalTemporaryAccountIPViewersWithEnabledPreferenceMetric
 */
class LocalTemporaryAccountIPViewersWithEnabledPreferenceMetricTest extends MediaWikiIntegrationTestCase {

	public function setUp(): void {
		parent::setUp();
		// We don't want to test specifically the CentralAuth implementation of the CentralIdLookup. As such, force it
		// to be the local provider.
		$this->overrideConfigValue( MainConfigNames::CentralIdLookupProvider, 'local' );
	}

	private function getObjectUnderTest() {
		/** @var WikimediaEventsMetricsFactory $metricsFactory */
		$metricsFactory = $this->getServiceContainer()->get( 'WikimediaEventsMetricsFactory' );
		return $metricsFactory->newMetric( LocalTemporaryAccountIPViewersWithEnabledPreferenceMetric::class );
	}

	private function setUpLocalGroupPermissions() {
		// Set some fake permission levels for the tests.
		$this->setGroupPermissions( [
			'checkuser' => [ 'checkuser-temporary-account-no-preference' => true ],
			'suppress' => [ 'checkuser-temporary-account-no-preference' => true ],
			'extendedconfirmed' => [ 'checkuser-temporary-account' => true ],
			'autoconfirmed' => [ 'checkuser-temporary-account-no-preference' => false ],
		] );
	}

	public function testCalculateForOnlyGlobalPreferencesChecked() {
		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalPreferences' );

		$this->setUpLocalGroupPermissions();
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->rows( [
				// Check the preference for users who either have auto-enrolled access or no access. These should not
				// be included in the count.
				[
					'gp_user' => $this->getAutoEnrolledAccessUser()->getId(),
					'gp_property' => 'checkuser-temporary-account-enable', 'gp_value' => '1',
				],
				[
					'gp_user' => $this->getUserWithoutAccess()->getId(),
					'gp_property' => 'checkuser-temporary-account-enable', 'gp_value' => '1',
				],
				// Disable the preference for one user and enable it for another user, where both users need to
				// check the preference to enable the tool.
				[
					'gp_user' => $this->getNonAutoEnrolledAccessUser()->getId(),
					'gp_property' => 'checkuser-temporary-account-enable', 'gp_value' => '1',
				],
				[
					'gp_user' => $this->getNonAutoEnrolledAccessUser()->getId(),
					'gp_property' => 'checkuser-temporary-account-enable', 'gp_value' => '0',
				],
			] )
			->caller( __METHOD__ )
			->execute();
		// Check that the count is as expected
		$this->assertSame( 1, $this->getObjectUnderTest()->calculate() );
	}

	public function testCalculateForOnlyLocalPreferencesChecked() {
		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalPreferences' );

		$this->setUpLocalGroupPermissions();
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'user_properties' )
			->rows( [
				// Check the preference for users who either have auto-enrolled access or no access. These should not
				// be included in the count.
				[
					'up_user' => $this->getAutoEnrolledAccessUser()->getId(),
					'up_property' => 'checkuser-temporary-account-enable', 'up_value' => '1',
				],
				[
					'up_user' => $this->getUserWithoutAccess()->getId(),
					'up_property' => 'checkuser-temporary-account-enable', 'up_value' => '1',
				],
				// Disable the preference for one user and enable it for another user, where both users need to
				// check the preference to enable the tool.
				[
					'up_user' => $this->getNonAutoEnrolledAccessUser()->getId(),
					'up_property' => 'checkuser-temporary-account-enable', 'up_value' => '1',
				],
				[
					'up_user' => $this->getNonAutoEnrolledAccessUser()->getId(),
					'up_property' => 'checkuser-temporary-account-enable', 'up_value' => '0',
				],
			] )
			->caller( __METHOD__ )
			->execute();
		// Check that the count is as expected
		$this->assertSame( 1, $this->getObjectUnderTest()->calculate() );
	}

	public function testCalculateForMixOfGlobalAndLocalPreferences() {
		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalPreferences' );
		$this->setUpLocalGroupPermissions();
		// Have one user have the preference enabled globally, but disabled locally.
		$firstNonAutoEnrolledAccessUser = $this->getNonAutoEnrolledAccessUser();
		$globalPreferencesStorageForFirstUser = new Storage( $firstNonAutoEnrolledAccessUser->getId() );
		$globalPreferencesStorageForFirstUser->save( [ 'checkuser-temporary-account-enable' => 1 ], [] );
		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption(
			$firstNonAutoEnrolledAccessUser, 'checkuser-temporary-account-enable', 0,
			UserOptionsManager::GLOBAL_OVERRIDE
		);
		$userOptionsManager->saveOptions( $firstNonAutoEnrolledAccessUser );
		// Have the second user have the preference enabled just locally
		$secondNonAutoEnrolledAccessUser = $this->getNonAutoEnrolledAccessUser();
		$userOptionsManager->setOption(
			$secondNonAutoEnrolledAccessUser, 'checkuser-temporary-account-enable', 1,
			UserOptionsManager::GLOBAL_OVERRIDE
		);
		$userOptionsManager->saveOptions( $secondNonAutoEnrolledAccessUser );
		// Have the third user have the preference enabled globally and locally
		$thirdNonAutoEnrolledAccessUser = $this->getNonAutoEnrolledAccessUser();
		$globalPreferencesStorageForThirdUser = new Storage( $thirdNonAutoEnrolledAccessUser->getId() );
		$globalPreferencesStorageForThirdUser->save( [ 'checkuser-temporary-account-enable' => 1 ], [] );
		$userOptionsManager->setOption(
			$thirdNonAutoEnrolledAccessUser, 'checkuser-temporary-account-enable', 1,
			UserOptionsManager::GLOBAL_OVERRIDE
		);
		$userOptionsManager->saveOptions( $thirdNonAutoEnrolledAccessUser );
		// Check that the count is as expected
		$this->assertSame( 2, $this->getObjectUnderTest()->calculate() );
	}

	public function testCalculateWhenAllUsersHaveAutoEnrolledAccess() {
		$this->setUpLocalGroupPermissions();
		// Add one testing user with auto-enrolled access, which has a preference value that should be ignored.
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'user_properties' )
			->row( [
				'up_user' => $this->getAutoEnrolledAccessUser()->getId(),
				'up_property' => 'checkuser-temporary-account-enable', 'up_value' => '1',
			] )
			->caller( __METHOD__ )
			->execute();
		// Check that the count is 0, as the only user in the DB has auto-enrolled access.
		$this->assertSame( 0, $this->getObjectUnderTest()->calculate() );
	}

	public function testCalculateWhenNoUsersInRelevantGroups() {
		$this->setUpLocalGroupPermissions();
		$this->assertSame( 0, $this->getObjectUnderTest()->calculate() );
	}

	public function testCalculateWhenNoRelevantGroups() {
		$groupPermissionsLookup = $this->getServiceContainer()->getGroupPermissionsLookup();
		$groupsWithNonAutoEnrolledAccess = $groupPermissionsLookup->getGroupsWithPermission(
			'checkuser-temporary-account'
		);
		$groupsWithAutoEnrolledAccess = $groupPermissionsLookup->getGroupsWithPermission(
			'checkuser-temporary-account-no-preference'
		);
		$groupsToRevokeAccessFrom = array_unique( array_merge(
			$groupsWithNonAutoEnrolledAccess, $groupsWithAutoEnrolledAccess
		) );
		$groupPermissions = [];
		foreach ( $groupsToRevokeAccessFrom as $group ) {
			$groupPermissions[$group] = [
				'checkuser-temporary-account-no-preference' => false,
				'checkuser-temporary-account' => false,
			];
		}
		$this->setGroupPermissions( $groupPermissions );
		$this->assertSame( 0, $this->getObjectUnderTest()->calculate() );
	}

	public function testGetName() {
		$this->assertSame(
			'local_temporary_account_ip_viewers_with_enabled_preference_total',
			$this->getObjectUnderTest()->getName()
		);
	}

	private function getAutoEnrolledAccessUser(): User {
		$autoEnrolledAccessUser = $this->getMutableTestUser(
			[ 'checkuser', 'suppress', 'extendedconfirmed' ]
		)->getUser();
		$autoEnrolledAccessUser->addToDatabase();
		return $autoEnrolledAccessUser;
	}

	private function getNonAutoEnrolledAccessUser(): User {
		$firstNonAutoEnrolledAccessUser = $this->getMutableTestUser( [ 'extendedconfirmed' ] )->getUser();
		$firstNonAutoEnrolledAccessUser->addToDatabase();
		return $firstNonAutoEnrolledAccessUser;
	}

	private function getUserWithoutAccess(): User {
		$userWithoutAccess = $this->getMutableTestUser( [ 'autoconfirmed' ] )->getUser();
		$userWithoutAccess->addToDatabase();
		return $userWithoutAccess;
	}
}
