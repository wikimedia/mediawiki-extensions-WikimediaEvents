<?php

namespace WikimediaEvents\PeriodicMetrics;

use MediaWiki\Permissions\GroupPermissionsLookup;
use MediaWiki\User\UserGroupManager;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * A metric for the total number of users with rights to see temporary account IP addresses, including
 * those users which have not checked the relevant preference (but still hold the right).
 */
class LocalTemporaryAccountIPViewersMetric extends PerWikiMetric {

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
		// Get a list of the local groups which give access to see temporary account IP addresses.
		$groupsWithAccess = $this->groupPermissionsLookup->getGroupsWithPermission(
			'checkuser-temporary-account-no-preference'
		);
		$groupsWithAccess = array_merge(
			$groupsWithAccess,
			$this->groupPermissionsLookup->getGroupsWithPermission( 'checkuser-temporary-account' )
		);
		$groupsWithAccess = array_unique( $groupsWithAccess );

		// Fetch the number of users which have any of the groups which give access to temporary account IP addresses.
		return $this->userGroupManager->newQueryBuilder( $this->dbProvider->getReplicaDatabase() )
			->clearFields()
			->select( 'COUNT(DISTINCT ug_user)' )
			->where( [ 'ug_group' => $groupsWithAccess ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'local_temporary_account_ip_viewers_total';
	}
}
