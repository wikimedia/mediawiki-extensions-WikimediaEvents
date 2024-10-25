<?php

namespace WikimediaEvents\PeriodicMetrics;

use MediaWiki\Extension\CentralAuth\CentralAuthDatabaseManager;
use MediaWiki\Extension\CentralAuth\GlobalGroup\GlobalGroupLookup;

/**
 * A metric for the total number of users who have the checkuser-temporary-account-no-preference
 * right through a global group.
 */
class GloballyAutoEnrolledTemporaryAccountIPViewersMetric implements IMetric {

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
		$globalGroupsWithRelevantPermission = $this->globalGroupLookup->getGroupsWithPermission(
			'checkuser-temporary-account-no-preference'
		);
		if ( !count( $globalGroupsWithRelevantPermission ) ) {
			return 0;
		}

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
		return 'globally_auto_enrolled_temporary_account_ip_viewers_total';
	}
}
