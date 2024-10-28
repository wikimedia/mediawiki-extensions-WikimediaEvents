<?php

namespace WikimediaEvents\PeriodicMetrics;

use GlobalPreferences\Services\GlobalPreferencesConnectionProvider;
use MediaWiki\Extension\CentralAuth\CentralAuthDatabaseManager;
use MediaWiki\Extension\CentralAuth\GlobalGroup\GlobalGroupLookup;

/**
 * A metric for the total number of users who have accepted the global preference to see temporary account IP
 * addresses and have the rights to see temporary account IP addresses through a global group.
 * This count excludes the number of users who are auto-enrolled to see temporary account IP addresses through
 * a global group.
 */
class GlobalTemporaryAccountIPViewersWithEnabledPreferenceMetric implements IMetric {

	private GlobalGroupLookup $globalGroupLookup;
	private CentralAuthDatabaseManager $centralAuthDatabaseManager;
	private GlobalPreferencesConnectionProvider $globalPreferencesConnectionProvider;

	public function __construct(
		GlobalGroupLookup $globalGroupLookup,
		CentralAuthDatabaseManager $centralAuthDatabaseManager,
		GlobalPreferencesConnectionProvider $globalPreferencesConnectionProvider
	) {
		$this->globalGroupLookup = $globalGroupLookup;
		$this->centralAuthDatabaseManager = $centralAuthDatabaseManager;
		$this->globalPreferencesConnectionProvider = $globalPreferencesConnectionProvider;
	}

	/** @inheritDoc */
	public function calculate(): int {
		// Get a list of the global groups which have the checkuser-temporary-account group but not the
		// checkuser-temporary-account-no-preference group.
		$groupsWithNonAutoEnrolledAccess = $this->globalGroupLookup->getGroupsWithPermission(
			'checkuser-temporary-account'
		);
		$groupsWithAutoEnrolledAccess = $this->globalGroupLookup->getGroupsWithPermission(
			'checkuser-temporary-account-no-preference'
		);

		$relevantGlobalGroups = array_diff(
			$groupsWithNonAutoEnrolledAccess, $groupsWithAutoEnrolledAccess
		);
		if ( !count( $relevantGlobalGroups ) ) {
			return 0;
		}

		$usersWhoHaveEnabledTheGlobalPreference = 0;
		$dbr = $this->centralAuthDatabaseManager->getCentralReplicaDB();
		$lastCentralId = 0;
		do {
			// Get a batch of users which have any of the global groups which grant the checkuser-temporary-account
			// right.
			$batchOfCentralIds = $dbr->newSelectQueryBuilder()
				->select( 'gug_user' )
				->distinct()
				->from( 'global_user_groups' )
				->where( [
					'gug_group' => $relevantGlobalGroups,
					$dbr->expr( 'gug_user', '>', $lastCentralId )
				] )
				->orderBy( 'gug_user' )
				->limit( 500 )
				->caller( __METHOD__ )
				->fetchFieldValues();

			if ( !count( $batchOfCentralIds ) ) {
				break;
			}
			$lastCentralId = end( $batchOfCentralIds );
			reset( $batchOfCentralIds );

			// Exclude users which have any global group which gives them the checkuser-temporary-account-no-preference
			// right, as these users have auto-enrolled access.
			$centralIdsToExcludeFromTheBatch = [];
			if ( count( $groupsWithAutoEnrolledAccess ) ) {
				$centralIdsToExcludeFromTheBatch = $dbr->newSelectQueryBuilder()
					->select( 'gug_user' )
					->distinct()
					->from( 'global_user_groups' )
					->where( [
						'gug_group' => $groupsWithAutoEnrolledAccess,
						'gug_user' => $batchOfCentralIds,
					] )
					->caller( __METHOD__ )
					->fetchFieldValues();
			}
			$batchOfCentralIds = array_diff( $batchOfCentralIds, $centralIdsToExcludeFromTheBatch );
			if ( !count( $batchOfCentralIds ) ) {
				// Try the next batch if every user in this batch has auto-enrolled access.
				continue;
			}

			// Calculate the number of users in our batch to process that have the global preference enabled, and
			// add this to the count to be returned.
			// We need to perform a lookup via DB query, instead of using the GlobalPreferencesFactory, so that
			// we can get a fresh result (no caching) and also batch checking the users to avoid needing to
			// perform a query per user.
			$usersWhoHaveEnabledTheGlobalPreference += $this->globalPreferencesConnectionProvider->getReplicaDatabase()
				->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'global_preferences' )
				->where( [
					'gp_user' => $batchOfCentralIds,
					'gp_property' => 'checkuser-temporary-account-enable',
					'gp_value' => 1,
				] )
				->caller( __METHOD__ )
				->fetchField();
		} while ( $lastCentralId );

		return $usersWhoHaveEnabledTheGlobalPreference;
	}

	/** @inheritDoc */
	public function getLabels(): array {
		return [];
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'global_temporary_account_ip_viewers_with_enabled_preference_total';
	}
}
