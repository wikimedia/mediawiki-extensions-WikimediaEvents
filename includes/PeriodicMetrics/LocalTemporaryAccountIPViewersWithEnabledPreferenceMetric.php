<?php

namespace WikimediaEvents\PeriodicMetrics;

use CentralIdLookup;
use ExtensionRegistry;
use GlobalPreferences\GlobalPreferencesServices;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\GroupPermissionsLookup;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserOptionsLookup;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * A metric for the total number of users who have accepted either the global preference or local preference
 * on the current wiki to see temporary account IP addresses, and have access to see these temporary account
 * IP addresses through a local group that requires the preference to be checked.
 * This count excludes the number of users who are auto-enrolled to see temporary account IP addresses through
 * a local group.
 */
class LocalTemporaryAccountIPViewersWithEnabledPreferenceMetric extends PerWikiMetric {

	private GroupPermissionsLookup $groupPermissionsLookup;
	private UserGroupManager $userGroupManager;
	private IConnectionProvider $dbProvider;
	private ExtensionRegistry $extensionRegistry;
	private CentralIdLookup $centralIdLookup;
	private UserIdentityLookup $userIdentityLookup;

	public function __construct(
		GroupPermissionsLookup $groupPermissionsLookup,
		UserGroupManager $userGroupManager,
		IConnectionProvider $dbProvider,
		ExtensionRegistry $extensionRegistry,
		CentralIdLookup $centralIdLookup,
		UserIdentityLookup $userIdentityLookup
	) {
		$this->groupPermissionsLookup = $groupPermissionsLookup;
		$this->userGroupManager = $userGroupManager;
		$this->dbProvider = $dbProvider;
		$this->extensionRegistry = $extensionRegistry;
		$this->centralIdLookup = $centralIdLookup;
		$this->userIdentityLookup = $userIdentityLookup;
	}

	/** @inheritDoc */
	public function calculate(): int {
		// Get a list of the local groups which have the checkuser-temporary-account group but not the
		// checkuser-temporary-account-no-preference group.
		$groupsWithNonAutoEnrolledAccess = $this->groupPermissionsLookup->getGroupsWithPermission(
			'checkuser-temporary-account'
		);
		$groupsWithAutoEnrolledAccess = $this->groupPermissionsLookup->getGroupsWithPermission(
			'checkuser-temporary-account-no-preference'
		);
		$relevantLocalGroups = array_diff( $groupsWithNonAutoEnrolledAccess, $groupsWithAutoEnrolledAccess );
		if ( !count( $relevantLocalGroups ) ) {
			return 0;
		}

		$usersWhoHaveEnabledThePreference = 0;
		$dbr = $this->dbProvider->getReplicaDatabase();
		$lastUserId = 0;
		do {
			// Get a batch of users which have any of the local groups which grant the checkuser-temporary-account
			// right.
			$batchOfUserIds = $this->userGroupManager->newQueryBuilder( $dbr )
				->clearFields()
				->select( 'ug_user' )
				->distinct()
				->where( [
					'ug_group' => $relevantLocalGroups,
					$dbr->expr( 'ug_user', '>', $lastUserId )
				] )
				->orderBy( 'ug_user' )
				->limit( 500 )
				->caller( __METHOD__ )
				->fetchFieldValues();

			if ( !count( $batchOfUserIds ) ) {
				break;
			}
			$lastUserId = end( $batchOfUserIds );
			reset( $batchOfUserIds );

			// Exclude users which have any local group which gives them the checkuser-temporary-account-no-preference
			// right, as these users have auto-enrolled access.
			$userIdsToExcludeFromTheBatch = [];
			if ( count( $groupsWithAutoEnrolledAccess ) ) {
				$userIdsToExcludeFromTheBatch = $this->userGroupManager->newQueryBuilder( $dbr )
					->clearFields()
					->select( 'ug_user' )
					->distinct()
					->where( [
						'ug_group' => $groupsWithAutoEnrolledAccess,
						'ug_user' => $batchOfUserIds,
					] )
					->caller( __METHOD__ )
					->fetchFieldValues();
			}

			$batchOfUserIds = array_diff( $batchOfUserIds, $userIdsToExcludeFromTheBatch );
			if ( !count( $batchOfUserIds ) ) {
				// Try the next batch if all users in this batch have auto-enrolled access.
				continue;
			}

			$userIdsWithGlobalPreferenceEnabled = [];
			if ( $this->extensionRegistry->isLoaded( 'GlobalPreferences' ) ) {
				// Get a list of the central IDs for each of the user IDs in our batch.
				$userIdToCentralId = [];
				$batchOfLocalUserIdentities = $this->userIdentityLookup->newSelectQueryBuilder()
					->whereUserIds( $batchOfUserIds )
					->caller( __METHOD__ )
					->fetchUserIdentities();
				foreach ( $batchOfLocalUserIdentities as $localUser ) {
					$centralId = $this->centralIdLookup->centralIdFromLocalUser(
						$localUser, CentralIdLookup::AUDIENCE_RAW
					);
					if ( $centralId ) {
						$userIdToCentralId[$localUser->getId()] = $centralId;
					}
				}

				if ( count( $userIdToCentralId ) ) {
					// We need to perform a lookup via DB query, instead of using the GlobalPreferencesFactory, so that
					// we can get a fresh result (no caching) and also batch checking the users to avoid needing to
					// perform a query per user.
					$globalPreferencesServices = GlobalPreferencesServices::wrap( MediaWikiServices::getInstance() );
					$globalPreferencesConnectionProvider = $globalPreferencesServices
						->getGlobalPreferencesConnectionProvider();
					$globalPreferencesLookupResult = $globalPreferencesConnectionProvider->getReplicaDatabase()
						->newSelectQueryBuilder()
						->select( 'gp_user' )
						->from( 'global_preferences' )
						->where( [
							'gp_user' => array_values( $userIdToCentralId ),
							'gp_property' => 'checkuser-temporary-account-enable',
							'gp_value' => 1,
						] )
						->caller( __METHOD__ )
						->fetchFieldValues();
					// Keep a track of which users have the global preference enabled, so that these can be
					// considered in the count to be returned.
					$userIdsWithGlobalPreferenceEnabled = array_map(
						static function ( $centralId ) use ( $userIdToCentralId ) {
							return strval( array_flip( $userIdToCentralId )[$centralId] ?? 0 );
						},
						$globalPreferencesLookupResult
					);
					$userIdsWithGlobalPreferenceEnabled = array_filter( $userIdsWithGlobalPreferenceEnabled );
				}
			}

			// Fetch the local preference values and local exception rows for the user IDs in our batch.
			$localPreferenceNameForOverride = 'checkuser-temporary-account-enable' .
				UserOptionsLookup::LOCAL_EXCEPTION_SUFFIX;
			$localPreferencesForUserBatch = $dbr->newSelectQueryBuilder()
				->select( [ 'up_property', 'up_user' ] )
				->from( 'user_properties' )
				->where( [
					'up_user' => $batchOfUserIds,
					'up_property' => [
						'checkuser-temporary-account-enable',
						$localPreferenceNameForOverride,
					],
					'up_value' => 1,
				] )
				->caller( __METHOD__ )
				->fetchResultSet();

			$userIdsWithLocalPreferenceEnabled = [];
			foreach ( $localPreferencesForUserBatch as $localPreferenceRow ) {
				if ( $localPreferenceRow->up_property === $localPreferenceNameForOverride ) {
					// If the user has the preference enabled globally but the setting is overridden locally,
					// then remove the user ID from the global preferences array. If the preference is enabled
					// locally, then it will be separately counted.
					$userIdKey = array_search( $localPreferenceRow->up_user, $userIdsWithGlobalPreferenceEnabled );
					if ( $userIdKey !== false ) {
						unset( $userIdsWithGlobalPreferenceEnabled[$userIdKey] );
					}
				} elseif ( $localPreferenceRow->up_property === 'checkuser-temporary-account-enable' ) {
					// If the preference is enabled locally, then mark it as such.
					$userIdsWithLocalPreferenceEnabled[] = $localPreferenceRow->up_user;
				}
			}

			// Combine the array of users with the global preference enabled and local preference enabled,
			// being sure to not double count users who appear in both lists.
			$usersWhoHaveEnabledThePreference += count( array_unique( array_merge(
				$userIdsWithLocalPreferenceEnabled, $userIdsWithGlobalPreferenceEnabled
			) ) );
		} while ( $lastUserId );

		return $usersWhoHaveEnabledThePreference;
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'local_temporary_account_ip_viewers_with_enabled_preference_total';
	}
}
