<?php

namespace WikimediaEvents;

use LoginNotify\LoginNotify;
use MediaWiki\Config\Config;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Extension\OATHAuth\OATHAuthServices;
use MediaWiki\Extension\OATHAuth\OATHUserRepository;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use Psr\Log\LoggerInterface;

class EmailAuthHooks {
	private ExtensionRegistry $extensionRegistry;
	private Config $config;
	private ?OATHUserRepository $userRepository;
	private ?IPReputationIPoidDataLookup $ipReputationDataLookup;
	private ?LoginNotify $loginNotify;
	private LoggerInterface $logger;

	public function __construct(
		ExtensionRegistry $extensionRegistry,
		Config $config,
		?OATHUserRepository $userRepository,
		?IPReputationIPoidDataLookup $ipReputationDataLookup,
		?LoginNotify $loginNotify,
		LoggerInterface $logger
	) {
		$this->extensionRegistry = $extensionRegistry;
		$this->config = $config;
		$this->userRepository = $userRepository;
		$this->ipReputationDataLookup = $ipReputationDataLookup;
		$this->loginNotify = $loginNotify;
		$this->logger = $logger;
	}

	public static function factory(): self {
		$services = MediaWikiServices::getInstance();
		$extensionRegistry = $services->getExtensionRegistry();

		$userRepository = null;

		if ( $extensionRegistry->isLoaded( 'OATHAuth' ) ) {
			$oathAuthServices = new OATHAuthServices( $services );
			$userRepository = $oathAuthServices->getUserRepository();
		}

		$ipReputationDataLookup = null;
		if ( $extensionRegistry->isLoaded( 'IPReputation' ) ) {
			$ipReputationDataLookup = $services->get( 'IPReputationIPoidDataLookup' );
		}

		$loginNotify = null;
		if ( $extensionRegistry->isLoaded( 'LoginNotify' ) ) {
			$loginNotify = $services->get( 'LoginNotify.LoginNotify' );
		}

		return new self(
			$services->getExtensionRegistry(),
			$services->getMainConfig(),
			$userRepository,
			$ipReputationDataLookup,
			$loginNotify,
			LoggerFactory::getInstance( 'EmailAuth' )
		);
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
		$request = $user->getRequest();
		// For testing purposes:
		if ( $request->getCookie( 'forceEmailAuth', '' ) ) {
			$verificationRequired = true;
			return true;
		}

		// LoginNotify: not enabled for votewiki and legalteamwiki
		if ( !$this->extensionRegistry->isLoaded( 'LoginNotify' ) ||
			// IPReputation: not enabled on beta labs
			!$this->extensionRegistry->isLoaded( 'IPReputation' ) ||
			// OATHAuth: Enabled everywhere
			!$this->extensionRegistry->isLoaded( 'OATHAuth' )
		) {
			return true;
		}

		$ip = $request->getIP();

		$oathUser = $this->userRepository->findByUser( $user );
		$knownToIPoid = (bool)$this->ipReputationDataLookup->getIPoidDataForIp( $ip, __METHOD__ );
		$knownLoginNotify = $this->loginNotify->isKnownSystemFast( $user, $request );

		$userName = $user->getName();
		$isEmailConfirmed = $user->isEmailConfirmed();
		$userAgent = $request->getHeader( 'User-Agent' );

		if ( $oathUser->isTwoFactorAuthEnabled() ) {
			$this->logger->info( 'Email verification skipped for {user} with 2FA enabled', [
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
			$this->logger->info(
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
		$this->logger->info(
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
