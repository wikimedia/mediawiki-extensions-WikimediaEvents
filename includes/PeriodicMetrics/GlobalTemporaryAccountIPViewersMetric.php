<?php

namespace WikimediaEvents\PeriodicMetrics;

use MediaWiki\Extension\CentralAuth\CentralAuthDatabaseManager;
use MediaWiki\Extension\CentralAuth\GlobalGroup\GlobalGroupLookup;

/**
 * A metric for the total number of users with rights to see temporary account IP addresses where this access
 * comes through a global group, including those users which have not checked the relevant preference
 * (but still hold the right).
 */
class GlobalTemporaryAccountIPViewersMetric implements IMetric {

	private GlobalGroupLookup $globalGroupLookup;
	private CentralAuthDatabaseManager $centralAuthDatabaseManager;

	public function __construct(
		GlobalGroupLookup $globalGroupLookup,
		CentralAuthDatabaseManager $centralAuthDatabaseManager
	) {
		$this->globalGroupLookup = $globalGroupLookup;
		$this->centralAuthDatabaseManager = $centralAuthDatabaseManager;
	}

	/** @inheritDoc */
	public function calculate(): int {
		// Get a list of the global groups which give access to see temporary account IP addresses.
		$globalGroupsWithRelevantPermission = $this->globalGroupLookup->getGroupsWithPermission(
			'checkuser-temporary-account-no-preference'
		);
		$globalGroupsWithRelevantPermission = array_merge(
			$globalGroupsWithRelevantPermission,
			$this->globalGroupLookup->getGroupsWithPermission( 'checkuser-temporary-account' )
		);
		$globalGroupsWithRelevantPermission = array_unique( $globalGroupsWithRelevantPermission );
		if ( !count( $globalGroupsWithRelevantPermission ) ) {
			return 0;
		}

		// Fetch the number of users which have any of the groups which give access to temporary account IP addresses.
		$dbr = $this->centralAuthDatabaseManager->getCentralReplicaDB();
		return $dbr->newSelectQueryBuilder()
			->select( 'COUNT(DISTINCT gug_user)' )
			->from( 'global_user_groups' )
			->where( [ 'gug_group' => $globalGroupsWithRelevantPermission ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/** @inheritDoc */
	public function getLabels(): array {
		return [];
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'global_temporary_account_ip_viewers_total';
	}
}
