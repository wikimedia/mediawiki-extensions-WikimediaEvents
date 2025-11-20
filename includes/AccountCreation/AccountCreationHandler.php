<?php
namespace WikimediaEvents\AccountCreation;

use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\Hook\AuthManagerLoginAuthenticateAuditHook;
use MediaWiki\Output\Hook\BeforePageDisplayHook;

class AccountCreationHandler implements
	AuthManagerLoginAuthenticateAuditHook,
	BeforePageDisplayHook
{

	public function __construct(
		private AccountCreationLogger $accountCreationLogger
	) {
	}

	/** @inheritDoc */
	public function onAuthManagerLoginAuthenticateAudit( $response, $user, $username, $extraData ) {
		$eventType = $response->status === AuthenticationResponse::PASS ? 'success' : 'failure';
		'@phan-var array{performer:\MediaWiki\User\User} $extraData';
		$this->accountCreationLogger->logLoginEvent( $eventType, $extraData[ 'performer' ]->getUser(), $response );
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$this->accountCreationLogger->logPageImpression(
			$out->getTitle(),
			$out->getRequest()->getSession()->getUser(),
			$out->getRequest()
		);
	}
}
