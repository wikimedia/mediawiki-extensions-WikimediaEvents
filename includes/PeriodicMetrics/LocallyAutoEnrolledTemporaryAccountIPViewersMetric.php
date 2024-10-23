<?php

namespace WikimediaEvents\PeriodicMetrics;

use MediaWiki\Permissions\GroupPermissionsLookup;
use MediaWiki\User\UserGroupManager;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * A metric for the total number of users who have the checkuser-temporary-account-no-preference
 * right through a local group.
 */
class LocallyAutoEnrolledTemporaryAccountIPViewersMetric extends PerWikiMetric {

	private GroupPermissionsLookup $groupPermissionsLookup;
	private UserGroupManager $userGroupManager;
	private IConnectionProvider $dbProvider;

	public function __construct(
		GroupPermissionsLookup $groupPermissionsLookup,
		UserGroupManager $userGroupManager,
		IConnectionProvider $dbProvider
	) {
		$this->groupPermissionsLookup = $groupPermissionsLookup;
		$this->userGroupManager = $userGroupManager;
		$this->dbProvider = $dbProvider;
	}

	/** @inheritDoc */
	public function calculate(): int {
		return $this->userGroupManager->newQueryBuilder( $this->dbProvider->getReplicaDatabase() )
			->clearFields()
			->select( 'COUNT(DISTINCT ug_user)' )
			->where( [
				'ug_group' => $this->groupPermissionsLookup->getGroupsWithPermission(
					'checkuser-temporary-account-no-preference'
				),
			] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'locally_auto_enrolled_temporary_account_ip_viewers_total';
	}
}
