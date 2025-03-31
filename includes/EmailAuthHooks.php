<?php

namespace WikimediaEvents;

use LoginNotify\LoginNotify;
use MediaWiki\Config\Config;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Extension\OATHAuth\OATHAuthServices;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;

class EmailAuthHooks {
	private ExtensionRegistry $extensionRegistry;
	private Config $config;

	public function __construct(
		ExtensionRegistry $extensionRegistry,
		Config $config
	) {
		$this->extensionRegistry = $extensionRegistry;
		$this->config = $config;
	}

	/**
	 * @param User $user
	 * @param bool &$verificationRequired
	 * @param string &$formMessage
	 * @param string &$subjectMessage
	 * @param string &$bodyMessage
	 * @return bool
	 */
	public function onEmailAuthRequireToken(
		$user, &$verificationRequired, &$formMessage, &$subjectMessage, &$bodyMessage
	) {
		// LoginNotify: not enabled for votewiki and legalteamwiki
		if ( !$this->extensionRegistry->isLoaded( 'LoginNotify' ) ||
			// IPReputation: not enabled on beta labs
			!$this->extensionRegistry->isLoaded( 'IPReputation' ) ||
			// OATHAuth: Enabled everywhere
			!$this->extensionRegistry->isLoaded( 'OATHAuth' )
		) {
			return true;
		}

		$logger = LoggerFactory::getInstance( 'EmailAuth' );
		$request = $user->getRequest();
		// For testing purposes:
		if ( $request->getCookie( 'forceEmailAuth', '' ) ) {
			$verificationRequired = true;
			return true;
		}
		$ip = $request->getIP();
		$services = MediaWikiServices::getInstance();
		$oathAuthServices = new OATHAuthServices( $services );
		$repository = $oathAuthServices->getUserRepository();
		$oathUser = $repository->findByUser( $user );
		/** @var IPReputationIPoidDataLookup $IPoidDataLookup */
		$IPoidDataLookup = $services->get( 'IPReputationIPoidDataLookup' );
		$knownToIPoid = (bool)$IPoidDataLookup->getIPoidDataForIp( $ip, __METHOD__ );
		/** @var LoginNotify $loginNotify */
		$loginNotify = $services->get( 'LoginNotify.LoginNotify' );
		$knownLoginNotify = $loginNotify->isKnownSystemFast( $user, $request );

		$userName = $user->getName();
		$isEmailConfirmed = $user->isEmailConfirmed();
		$userAgent = $request->getHeader( 'User-Agent' );

		if ( $oathUser->isTwoFactorAuthEnabled() ) {
			$logger->info( 'Email verification skipped for {user} with 2FA enabled', [
				'user' => $userName,
				'eventType' => 'emailauth-verification-skipped-2fa',
				'ua' => $userAgent,
				'ip' => $ip,
				'knownIPoid' => $knownToIPoid,
				'knownLoginNotify' => $knownLoginNotify,
			] );
			return true;
		}

		if ( $knownLoginNotify !== LoginNotify::USER_KNOWN && $knownToIPoid ) {
			// If we are in "enforce" mode, then actually require the email verification here.
			$verificationRequired = $this->config->get( 'WikimediaEventsEmailAuthEnforce' );
			$logger->info(
				'Email verification required for {user} without 2FA, not in LoginNotify, IP in IPoid',
				[
					'user' => $userName,
					'eventType' => 'emailauth-verification-required',
					'ip' => $ip,
					'emailVerified' => $isEmailConfirmed,
					'ua' => $userAgent,
					'knownIPoid' => $knownToIPoid,
					'knownLoginNotify' => $knownLoginNotify,
				]
			);
			return true;
		}
		$logger->info(
			'Email verification not required for {user}',
			[
				'user' => $userName,
				'ip' => $ip,
				'eventType' => 'emailauth-verification-not-required',
				'emailVerified' => $isEmailConfirmed,
				'ua' => $userAgent,
				'knownIPoid' => $knownToIPoid,
				'knownLoginNotify' => $knownLoginNotify,
			]
		);
		return true;
	}
}
