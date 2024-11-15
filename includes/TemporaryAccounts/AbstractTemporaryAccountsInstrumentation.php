<?php
namespace WikimediaEvents\TemporaryAccounts;

use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;
use Wikimedia\IPUtils;

/**
 * Abstract class used a as base for temporary account instrumentation hook handler classes that have need
 * to label an event by the type of user that performed the event and/or was targeted in the event.
 */
abstract class AbstractTemporaryAccountsInstrumentation {

	public const ACCOUNT_TYPE_TEMPORARY = 'temp';
	public const ACCOUNT_TYPE_ANON = 'anon';
	public const ACCOUNT_TYPE_IP_RANGE = 'iprange';
	public const ACCOUNT_TYPE_BOT = 'bot';
	public const ACCOUNT_TYPE_NORMAL = 'normal';

	protected UserIdentityUtils $userIdentityUtils;
	protected UserFactory $userFactory;

	public function __construct(
		UserIdentityUtils $userIdentityUtils,
		UserFactory $userFactory
	) {
		$this->userIdentityUtils = $userIdentityUtils;
		$this->userFactory = $userFactory;
	}

	/**
	 * Get the type of user for use as a Prometheus label.
	 * @param UserIdentity|string $user For single IP addresses, temporary accounts, named accounts,
	 *  and bot accounts, this will be a user identity. For IP ranges, this will be a string.
	 * @return string One of the TemporaryAccountsInstrumentation::ACCOUNT_TYPE_* constants
	 */
	protected function getUserType( $user ): string {
		if ( !$user instanceof UserIdentity ) {
			// Must be an IP range.
			return self::ACCOUNT_TYPE_IP_RANGE;
		}
		if ( $this->userIdentityUtils->isTemp( $user ) ) {
			return self::ACCOUNT_TYPE_TEMPORARY;
		}

		if ( IPUtils::isIPAddress( $user->getName() ) ) {
			return self::ACCOUNT_TYPE_ANON;
		}

		$user = $this->userFactory->newFromUserIdentity( $user );
		if ( $user->isBot() ) {
			return self::ACCOUNT_TYPE_BOT;
		}

		return self::ACCOUNT_TYPE_NORMAL;
	}
}
