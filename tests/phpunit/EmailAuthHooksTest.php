<?php
namespace WikimediaEvents\Tests\Unit;

use ArrayUtils;
use LoginNotify\LoginNotify;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\MutableConfig;
use MediaWiki\Extension\IPReputation\IPoidResponse;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Extension\OATHAuth\OATHUser;
use MediaWiki\Extension\OATHAuth\OATHUserRepository;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\WebRequest;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use WikimediaEvents\EmailAuthHooks;

/**
 * @covers \WikimediaEvents\EmailAuthHooks
 */
class EmailAuthHooksTest extends MediaWikiIntegrationTestCase {
	private ExtensionRegistry $extensionRegistry;
	private MutableConfig $config;
	private OATHUserRepository $userRepository;
	private IPReputationIPoidDataLookup $ipReputationDataLookup;
	private LoginNotify $loginNotify;
	private LoggerInterface $logger;

	private User $user;

	private EmailAuthHooks $hooks;

	protected function setUp(): void {
		parent::setUp();

		$this->markTestSkippedIfExtensionNotLoaded( 'IPReputation' );
		$this->markTestSkippedIfExtensionNotLoaded( 'OATHAuth' );
		$this->markTestSkippedIfExtensionNotLoaded( 'LoginNotify' );

		$this->extensionRegistry = $this->createMock( ExtensionRegistry::class );
		$this->config = new HashConfig( [
			'WikimediaEventsEmailAuthEnforce' => true,
		] );
		$this->userRepository = $this->createMock( OATHUserRepository::class );
		$this->ipReputationDataLookup = $this->createMock( IPReputationIPoidDataLookup::class );
		$this->loginNotify = $this->createMock( LoginNotify::class );
		$this->logger = $this->createMock( LoggerInterface::class );

		$this->user = $this->createMock( User::class );

		$this->hooks = new EmailAuthHooks(
			$this->extensionRegistry,
			$this->config,
			$this->userRepository,
			$this->ipReputationDataLookup,
			$this->loginNotify,
			$this->logger
		);
	}

	/**
	 * @dataProvider provideExtensionDependenciesNotMet
	 */
	public function testShouldDoNothingIfExtensionDependenciesNotMet(
		bool $ipReputationLoaded,
		bool $oathAuthLoaded,
		bool $loginNotifyLoaded
	): void {
		$this->extensionRegistry->method( 'isLoaded' )
			->willReturnMap( [
				[ 'IPReputation', '*', $ipReputationLoaded ],
				[ 'OATHAuth', '*', $oathAuthLoaded ],
				[ 'LoginNotify', '*', $loginNotifyLoaded ],
			] );

		$this->user->method( 'getRequest' )
			->willReturn( $this->createMock( WebRequest::class ) );

		$this->userRepository->expects( $this->never() )
			->method( $this->anything() );

		$this->ipReputationDataLookup->expects( $this->never() )
			->method( $this->anything() );

		$this->loginNotify->expects( $this->never() )
			->method( $this->anything() );

		$this->logger->expects( $this->never() )
			->method( $this->anything() );

		$verificationRequired = false;
		$res = $this->hooks->onEmailAuthRequireToken(
			$this->user,
			$verificationRequired,
			$formMessage,
			$subjectMessage,
			$bodyMessage
		);

		$this->assertTrue( $res );
		$this->assertFalse( $verificationRequired );
	}

	public static function provideExtensionDependenciesNotMet(): iterable {
		yield 'IPReputation not loaded' => [ false, true, true ];
		yield 'OATHAuth not loaded' => [ true, false, true ];
		yield 'LoginNotify not loaded' => [ true, true, false ];
	}

	/**
	 * @dataProvider provideDependencyStatusWhenForcedVerification
	 */
	public function testShouldAllowForcingVerificationViaCookieIrrespectiveOfDependencyStatus(
		bool $ipReputationLoaded,
		bool $oathAuthLoaded,
		bool $loginNotifyLoaded
	): void {
		$this->extensionRegistry->method( 'isLoaded' )
			->willReturnMap( [
				[ 'IPReputation', '*', $ipReputationLoaded ],
				[ 'OATHAuth', '*', $oathAuthLoaded ],
				[ 'LoginNotify', '*', $loginNotifyLoaded ],
			] );

		$request = new FauxRequest();
		$request->setCookie( 'forceEmailAuth', '1', '' );

		$this->user->method( 'getRequest' )
			->willReturn( $request );

		$this->userRepository->expects( $this->never() )
			->method( $this->anything() );

		$this->ipReputationDataLookup->expects( $this->never() )
			->method( $this->anything() );

		$this->loginNotify->expects( $this->never() )
			->method( $this->anything() );

		$this->logger->expects( $this->never() )
			->method( $this->anything() );

		$verificationRequired = false;
		$res = $this->hooks->onEmailAuthRequireToken(
			$this->user,
			$verificationRequired,
			$formMessage,
			$subjectMessage,
			$bodyMessage
		);

		$this->assertTrue( $res );
		$this->assertTrue( $verificationRequired );
	}

	public static function provideDependencyStatusWhenForcedVerification(): iterable {
		$testCases = ArrayUtils::cartesianProduct(
			// Whether IPReputation is loaded
			[ true, false ],
			// Whether OATHAuth is loaded
			[ true, false ],
			// Whether LoginNotify is loaded
			[ true, false ]
		);

		foreach ( $testCases as $params ) {
			[
				$ipReputationLoaded,
				$oathAuthLoaded,
				$loginNotifyLoaded
			] = $params;

			$description = sprintf(
				'IPReputation %s, OATHAuth %s, LoginNotify %s',
				$ipReputationLoaded ? 'loaded' : 'not loaded',
				$oathAuthLoaded ? 'loaded' : 'not loaded',
				$loginNotifyLoaded ? 'loaded' : 'not loaded'
			);

			yield $description => $params;
		}
	}

	/**
	 * @dataProvider provideVerificationCases
	 *
	 * @param bool $hasEnabledTwoFactorAuth Whether the user has 2FA enabled
	 * @param bool $isEmailConfirmed Whether the user's email is confirmed
	 * @param bool $isKnownToIpoid Whether the user's IP is known to ipoid/Spur
	 * @param string $isBotUser If the user is a bot.
	 * @param bool $shouldEnforceVerification The value of $wgWikimediaEventsEmailAuthEnforce
	 * @param string $knownLoginNotify The status of the user's IP according to LoginNotify
	 */
	public function testShouldRequireVerificationForNon2FAUsersOnUnknownIPsKnownToIpoid(
		bool $hasEnabledTwoFactorAuth,
		bool $isEmailConfirmed,
		bool $isKnownToIpoid,
		bool $isBotUser,
		bool $shouldEnforceVerification,
		string $knownLoginNotify
	): void {
		$knownLoginNotify = [
			'LoginNotify::USER_KNOWN' => LoginNotify::USER_KNOWN,
			'LoginNotify::USER_NOT_KNOWN' => LoginNotify::USER_NOT_KNOWN,
			'LoginNotify::USER_NO_INFO' => LoginNotify::USER_NO_INFO,
		][ $knownLoginNotify ];

		$this->extensionRegistry->method( 'isLoaded' )
			->willReturnMap( [
				[ 'IPReputation', '*', true ],
				[ 'OATHAuth', '*', true ],
				[ 'LoginNotify', '*', true ],
			] );

		$this->config->set( 'WikimediaEventsEmailAuthEnforce', $shouldEnforceVerification );

		$request = new FauxRequest();
		$request->setIP( '127.0.0.1' );
		$request->setHeader( 'User-Agent', 'Mozilla/5.0' );

		$this->user->method( 'getRequest' )
			->willReturn( $request );
		$this->user->method( 'isEmailConfirmed' )
			->willReturn( $isEmailConfirmed );
		$this->user->method( 'isBot' )->willReturn( $isBotUser );

		$oathUser = $this->createMock( OATHUser::class );
		$oathUser->method( 'isTwoFactorAuthEnabled' )
			->willReturn( $hasEnabledTwoFactorAuth );

		$this->userRepository->method( 'findByUser' )
			->with( $this->user )
			->willReturn( $oathUser );

		$this->ipReputationDataLookup->method( 'getIPoidDataForIp' )
			->with( $request->getIP(), EmailAuthHooks::class . '::onEmailAuthRequireToken' )
			->willReturn( $isKnownToIpoid ? $this->createMock( IPoidResponse::class ) : null );

		$this->loginNotify->method( 'isKnownSystemFast' )
			->with( $this->user, $request )
			->willReturn( $knownLoginNotify );

		$shouldRequireVerification = !$isBotUser && !$hasEnabledTwoFactorAuth &&
			$isKnownToIpoid &&
			$knownLoginNotify !== LoginNotify::USER_KNOWN;

		if ( $isBotUser ) {
			$this->logger->expects( $this->once() )
				->method( 'info' )
				->with( 'Email verification skipped for bot {user}',
					[
						'user' => $this->user->getName(),
						'eventType' => 'emailauth-verification-skipped-bot',
						'ua' => 'Mozilla/5.0',
						'ip' => $request->getIP(),
						'knownLoginNotify' => $knownLoginNotify,
						'knownIPoid' => $isKnownToIpoid,
					]
				);
		} elseif ( $hasEnabledTwoFactorAuth ) {
			$this->logger->expects( $this->once() )
				->method( 'info' )
				->with(
					'Email verification skipped for {user} with 2FA enabled',
					[
						'user' => $this->user->getName(),
						'eventType' => 'emailauth-verification-skipped-2fa',
						'ua' => 'Mozilla/5.0',
						'ip' => $request->getIP(),
						'knownIPoid' => $isKnownToIpoid,
						'knownLoginNotify' => $knownLoginNotify,
					]
				);
		} else {
			$logMsg = $shouldRequireVerification ?
				'Email verification required for {user} without 2FA, not in LoginNotify, IP in IPoid' :
				'Email verification not required for {user}';
			$eventType = $shouldRequireVerification ?
				'emailauth-verification-required' :
				'emailauth-verification-not-required';
			$this->logger->expects( $this->once() )
				->method( 'info' )
				->with(
					$logMsg,
					[
						'user' => $this->user->getName(),
						'eventType' => $eventType,
						'emailVerified' => $isEmailConfirmed,
						'ua' => 'Mozilla/5.0',
						'ip' => $request->getIP(),
						'knownIPoid' => $isKnownToIpoid,
						'knownLoginNotify' => $knownLoginNotify,
					]
				);
		}

		$verificationRequired = false;
		$res = $this->hooks->onEmailAuthRequireToken(
			$this->user,
			$verificationRequired,
			$formMessage,
			$subjectMessage,
			$bodyMessage
		);

		$this->assertTrue( $res );
		$this->assertSame(
			$shouldRequireVerification && $shouldEnforceVerification,
			$verificationRequired
		);
	}

	public static function provideVerificationCases(): iterable {
		$testCases = ArrayUtils::cartesianProduct(
			// 2FA status
			[ true, false ],
			// Email confirmation status
			[ true, false ],
			// Whether the IP is known to ipoid
			[ true, false ],
			// Whether the user is a bot
			[ true, false ],
			// $wgWikimediaEventsEmailAuthEnforce
			[ true, false ],
			// Whether the IP is a known IP according to LoginNotify
			[
				// LoginNotify is not guaranteed to be installed at this point
				'LoginNotify::USER_KNOWN',
				'LoginNotify::USER_NOT_KNOWN',
				'LoginNotify::USER_NO_INFO',
			]
		);

		foreach ( $testCases as $params ) {
			[
				$hasEnabledTwoFactorAuth,
				$isEmailConfirmed,
				$isKnownToIpoid,
				$isBotUser,
				$shouldEnforceVerification,
				$knownLoginNotify
			] = $params;

			$description = sprintf(
				'2FA %s, %s, %s, %s, $wgWikimediaEventsEmailAuthEnforce: %s, LoginNotify status: %s',
				$hasEnabledTwoFactorAuth ? 'enabled' : 'disabled',
				$isEmailConfirmed ? 'email confirmed' : 'email not confirmed',
				$isKnownToIpoid ? 'known to ipoid' : 'not known to ipoid',
				$isBotUser ? 'bot user' : 'not bot user',
				$shouldEnforceVerification ? 'true' : 'false',
				$knownLoginNotify
			);

			yield $description => $params;
		}
	}
}
