<?php
namespace WikimediaEvents\TemporaryAccounts;

use MediaWiki\Extension\GlobalBlocking\GlobalBlock;
use MediaWiki\Extension\GlobalBlocking\Hooks\GlobalBlockingGlobalBlockAuditHook;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityUtils;
use Wikimedia\Stats\StatsFactory;

/**
 * Holds hook handlers for GlobalBlocking hooks related to the temporary accounts initiative (T357763).
 */
class GlobalBlockingTemporaryAccountsInstrumentation extends AbstractTemporaryAccountsInstrumentation implements
	GlobalBlockingGlobalBlockAuditHook
{

	private StatsFactory $statsFactory;

	public function __construct(
		UserIdentityUtils $userIdentityUtils,
		UserFactory $userFactory,
		StatsFactory $statsFactory
	) {
		parent::__construct( $userIdentityUtils, $userFactory );
		$this->statsFactory = $statsFactory;
	}

	/** @inheritDoc */
	public function onGlobalBlockingGlobalBlockAudit( GlobalBlock $globalBlock ) {
		$target = $globalBlock->getTargetUserIdentity() ?? $globalBlock->getTargetName();
		$this->statsFactory->withComponent( 'WikimediaEvents' )
			->getCounter( 'global_block_target_total' )
			->setLabel( 'user', $this->getUserType( $target ) )
			->increment();
	}
}
