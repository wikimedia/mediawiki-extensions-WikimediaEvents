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
use MediaWiki\User\UserEditTracker;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class EmailAuthHooks {
	private ExtensionRegistry $extensionRegistry;
	private Config $config;
	private UserEditTracker $userEditTracker;
	private ?OATHUserRepository $oathUserRepository;
	private ?IPReputationIPoidDataLookup $ipReputationDataLookup;
	private ?LoginNotify $loginNotify;
	/** @var callable|null */
	private $getPrivilegedGroupsCallback;
	private LoggerInterface $logger;

	public function __construct(
		ExtensionRegistry $extensionRegistry,
		Config $config,
		UserEditTracker $userEditTracker,
		?OATHUserRepository $oathUserRepository,
		?IPReputationIPoidDataLookup $ipReputationDataLookup,
		?LoginNotify $loginNotify,
		?callable $getPrivilegedGroupsCallback,
		LoggerInterface $logger
	) {
		$this->extensionRegistry = $extensionRegistry;
		$this->config = $config;
		$this->userEditTracker = $userEditTracker;
		$this->oathUserRepository = $oathUserRepository;
		$this->ipReputationDataLookup = $ipReputationDataLookup;
		$this->loginNotify = $loginNotify;
		$this->getPrivilegedGroupsCallback = $getPrivilegedGroupsCallback;
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
			$services->getUserEditTracker(),
			$userRepository,
			$ipReputationDataLookup,
			$loginNotify,
			// defined in wmf-config/CommonSettings.php in the operations/mediawiki-config repo
			function_exists( 'wmfGetPrivilegedGroups' ) ? 'wmfGetPrivilegedGroups' : null,
			LoggerFactory::getInstance( 'EmailAuth' )
		);
	}

	/**
	 * @param User $user
	 * @param bool &$verificationRequired Set to true to require email verification
	 * @param string &$formMessage
	 * @param string &$subject
	 * @param string &$body
	 * @param string &$bodyHtml
	 * @return bool
	 */
	public function onEmailAuthRequireToken(
		$user, &$verificationRequired, &$formMessage, &$subject, &$body, &$bodyHtml
	) {
		$request = $user->getRequest();
		$ip = $request->getIP();
		$userName = $user->getName();
		$userAgent = $request->getHeader( 'User-Agent' );
		$forceEmailAuth = (bool)$request->getCookie( 'forceEmailAuth', '' );

		$isEmailConfirmed = $user->isEmailConfirmed();
		$isBot = $user->isBot();
		// one of the LoginNotify::USER_* constants
		$knownLoginNotify = 'no info';
		$knownToIPoid = false;
		$hasTwoFactorAuth = false;
		$privilegedGroups = [];

		$activeOnLocalWikiInLast90Days = false;
		$latestEditTimestamp = $this->userEditTracker->getLatestEditTimestamp( $user );
		if ( $latestEditTimestamp ) {
			$timeSinceLastEdit = ( new ConvertibleTimestamp( $latestEditTimestamp ) )
				->diff( new ConvertibleTimestamp() );
			$activeOnLocalWikiInLast90Days = $timeSinceLastEdit->format( '%a' ) <= 90;
		}

		if ( $this->extensionRegistry->isLoaded( 'LoginNotify' ) ) {
			$knownLoginNotify = $this->loginNotify->isKnownSystemFast( $user, $request );
		}
		if ( $this->extensionRegistry->isLoaded( 'IPReputation' ) ) {
			$knownToIPoid = (bool)$this->ipReputationDataLookup->getIPoidDataForIp( $ip, __METHOD__ );
		}
		if ( $this->extensionRegistry->isLoaded( 'OATHAuth' ) ) {
			$oathUser = $this->oathUserRepository->findByUser( $user );
			$hasTwoFactorAuth = $oathUser->isTwoFactorAuthEnabled();
		}
		if ( is_callable( $this->getPrivilegedGroupsCallback ) ) {
			$privilegedGroups = ( $this->getPrivilegedGroupsCallback )( $user );
		}

		$logData = [
			'user' => $userName,
			'ua' => $userAgent,
			'ip' => $ip,
			'emailVerified' => $isEmailConfirmed,
			'isBot' => $isBot,
			'knownIPoid' => $knownToIPoid,
			'knownLoginNotify' => $knownLoginNotify,
			'hasTwoFactorAuth' => $hasTwoFactorAuth,
			'forceEmailAuth' => $forceEmailAuth,
			'privilegedGroups' => array_fill_keys( $privilegedGroups, 1 ),
			'activeOnLocalWikiInLast90Days' => $activeOnLocalWikiInLast90Days,
		];

		if ( $forceEmailAuth ) {
			// Test mode, always require verification.
			$logMessage = 'Email verification skipped for {user} via test cookie';
			$eventType = 'emailauth-verification-forced';
			$verificationRequired = true;
		} elseif ( $isBot ) {
			$logMessage = 'Email verification skipped for bot {user}';
			$eventType = 'emailauth-verification-skipped-bot';
			$verificationRequired = false;
		} elseif ( $hasTwoFactorAuth ) {
			$logMessage = 'Email verification skipped for {user} with 2FA enabled';
			$eventType = 'emailauth-verification-skipped-2fa';
			$verificationRequired = false;
		} elseif ( $knownLoginNotify === LoginNotify::USER_KNOWN ) {
			$logMessage = 'Email verification skipped for {user} with known IP or device';
			$eventType = 'emailauth-verification-skipped-known-loginnotify';
			$verificationRequired = false;
		} elseif ( !$knownToIPoid ) {
			$logMessage = 'Email verification skipped for {user} with no bad IP reputation';
			$eventType = 'emailauth-verification-skipped-nobadip';
			$verificationRequired = false;
		} else {
			// If we are in "enforce" mode, then actually require the email verification here.
			$verificationRequired = $this->config->get( 'WikimediaEventsEmailAuthEnforce' );
			$logMessage = 'Email verification required for {user} without 2FA, unknown IP and device, '
				. 'bad IP reputation';
			$eventType = 'emailauth-verification-required';
		}

		$this->logger->info( $logMessage, $logData + [ 'eventType' => $eventType ] );
		return true;
	}
}
