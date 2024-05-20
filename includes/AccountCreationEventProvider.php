<?php
namespace WikimediaEvents;

use MediaWiki\Auth\AbstractPreAuthenticationProvider;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\User\User;

class AccountCreationEventProvider extends AbstractPreAuthenticationProvider {
	/**
	 * @var AccountCreationLogger
	 */
	private AccountCreationLogger $accountCreationLogger;

	public function __construct( AccountCreationLogger $accountCreationLogger ) {
		$this->accountCreationLogger = $accountCreationLogger;
	}

	/**
	 * @param User $user User that was attempted to be created.
	 * @param User $creator User doing the creation.
	 * @param AuthenticationResponse $response (PASS or FAIL).
	 *        Based on this response, the event type is set to 'success'
	 *        for PASS and 'failure' for FAIL.
	 */
	public function postAccountCreation(
		$user, $creator, AuthenticationResponse $response ) {
		$eventType = $response->status === AuthenticationResponse::PASS ? 'success' : 'failure';
		$this->accountCreationLogger->logAccountCreationEvent( $eventType, $creator, $response );
	}
}
