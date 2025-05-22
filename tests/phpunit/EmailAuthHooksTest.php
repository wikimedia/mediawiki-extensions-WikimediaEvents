<?php
namespace WikimediaEvents\Tests\Unit;

use LoginNotify\LoginNotify;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\MutableConfig;
use MediaWiki\Exception\MWException;
use MediaWiki\Extension\IPReputation\IPoidResponse;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Extension\OATHAuth\OATHUser;
use MediaWiki\Extension\OATHAuth\OATHUserRepository;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\User\User;
use MediaWiki\User\UserEditTracker;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use Wikimedia\NormalizedException\NormalizedException;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikimediaEvents\EmailAuthHooks;
use WikimediaEvents\Tests\ArrayHasSubset;
use WikimediaEvents\WikimediaEventsCountryCodeLookup;

/**
 * @covers \WikimediaEvents\EmailAuthHooks
 */
class EmailAuthHooksTest extends MediaWikiIntegrationTestCase {
	private ExtensionRegistry $extensionRegistry;
	private MutableConfig $config;
	private OATHUserRepository $userRepository;
	private UserEditTracker $userEditTracker;
	private IPReputationIPoidDataLookup $ipReputationDataLookup;
	private LoginNotify $loginNotify;
	private WikimediaEventsCountryCodeLookup $countryCodeLookup;
	private LoggerInterface $logger;

	private FauxRequest $request;
	private User $user;
	private EmailAuthHooks $hooks;

	protected function setUp(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'IPReputation' );
		$this->markTestSkippedIfExtensionNotLoaded( 'OATHAuth' );
		$this->markTestSkippedIfExtensionNotLoaded( 'LoginNotify' );
		$this->markTestSkippedIfExtensionNotLoaded( 'CLDR' );

		parent::setUp();

		$this->setMocks();
	}

	protected function setMocks( ?callable $getPrivilegedGroupsCallback = null ): void {
		$this->extensionRegistry = $this->createMock( ExtensionRegistry::class );
		$this->config = new HashConfig( [
			'WikimediaEventsEmailAuthEnforce' => true,
		] );
		$this->userEditTracker = $this->createMock( UserEditTracker::class );
		$this->userRepository = $this->createMock( OATHUserRepository::class );
		$this->ipReputationDataLookup = $this->createMock( IPReputationIPoidDataLookup::class );
		$this->loginNotify = $this->createMock( LoginNotify::class );
		$this->countryCodeLookup = $this->createMock( WikimediaEventsCountryCodeLookup::class );
		$this->logger = $this->createMock( LoggerInterface::class );

		$this->request = new FauxRequest();
		$this->user = $this->createMock( User::class );
		$this->user->method( 'getRequest' )->willReturn( $this->request );

		$this->hooks = new EmailAuthHooks(
			$this->extensionRegistry,
			$this->config,
			$this->userEditTracker,
			$this->userRepository,
			$this->ipReputationDataLookup,
			$this->loginNotify,
			$getPrivilegedGroupsCallback,
			$this->countryCodeLookup,
			$this->logger
		);
	}

	public function testShouldDoNothingIfExtensionDependenciesNotMet(): void {
		$this->extensionRegistry->method( 'isLoaded' )
			->willReturnMap( [
				[ 'IPReputation', '*', false ],
				[ 'OATHAuth', '*', false ],
				[ 'LoginNotify', '*', false ],
				[ 'CLDR', '*', false ],
			] );

		$this->userRepository->expects( $this->never() )
			->method( $this->anything() );

		$this->ipReputationDataLookup->expects( $this->never() )
			->method( $this->anything() );

		$this->loginNotify->expects( $this->never() )
			->method( $this->anything() );

		$verificationRequired = false;
		$res = $this->hooks->onEmailAuthRequireToken(
			$this->user,
			$verificationRequired,
			$formMessage,
			$subject,
			$body,
			$bodyHtml
		);

		$this->assertTrue( $res );
		$this->assertTrue( $verificationRequired );
	}

	public function testShouldAllowForcingVerificationViaCookie(): void {
		$this->extensionRegistry->method( 'isLoaded' )
			->willReturnMap( [
				[ 'IPReputation', '*', false ],
				[ 'OATHAuth', '*', false ],
				[ 'LoginNotify', '*', false ],
				[ 'CLDR', '*', false ],
			] );

		$this->request->setCookie( 'forceEmailAuth', '1', '' );

		$verificationRequired = false;
		$res = $this->hooks->onEmailAuthRequireToken(
			$this->user,
			$verificationRequired,
			$formMessage,
			$subject,
			$body,
			$bodyHtml
		);

		$this->assertTrue( $res );
		$this->assertTrue( $verificationRequired );
	}

	/**
	 * @dataProvider provideVerificationCases
	 *
	 * @param bool $hasEnabledTwoFactorAuth Whether the user has 2FA enabled
	 * @param bool $isEmailConfirmed Whether the user's email is confirmed
	 * @param bool $isKnownToIpoid Whether the user's IP is known to ipoid/Spur
	 * @param bool $isBotUser If the user is a bot.
	 * @param bool $shouldEnforceVerification The value of $wgWikimediaEventsEmailAuthEnforce
	 * @param string $knownLoginNotify The status of the user's IP according to LoginNotify
	 * @param ?bool $activeOnLocalWikiInLast90Days Null for no data, true/false for recent / old time
	 * @throws MWException
	 * @throws NormalizedException
	 */
	public function testShouldRequireVerificationForNon2FAUsersOnUnknownIPsKnownToIpoid(
		bool $hasEnabledTwoFactorAuth,
		bool $isEmailConfirmed,
		bool $isKnownToIpoid,
		bool $isBotUser,
		bool $shouldEnforceVerification,
		string $knownLoginNotify,
		?bool $activeOnLocalWikiInLast90Days
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
				[ 'CLDR', '*', true ],
			] );

		$this->config->set( 'WikimediaEventsEmailAuthEnforce', $shouldEnforceVerification );

		$this->request->setIP( '100.101.102.103' );
		$this->request->setHeader( 'User-Agent', 'Mozilla/5.0' );

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
			->with( '100.101.102.103', EmailAuthHooks::class . '::onEmailAuthRequireToken' )
			->willReturn( $isKnownToIpoid ? $this->createMock( IPoidResponse::class ) : null );

		$this->loginNotify->method( 'isKnownSystemFast' )
			->with( $this->user, $this->request )
			->willReturn( $knownLoginNotify );

		if ( $activeOnLocalWikiInLast90Days !== null ) {
			$lastEdit = ( new ConvertibleTimestamp() )->sub( $activeOnLocalWikiInLast90Days ? 'P10D' : 'P100D' );
			$this->userEditTracker->method( 'getLatestEditTimestamp' )
				->willReturn( $lastEdit->getTimestamp( TS_MW ) );
		}

		$shouldRequireVerification = !$isBotUser && !$hasEnabledTwoFactorAuth &&
			$knownLoginNotify !== LoginNotify::USER_KNOWN;

		$logData = [
			'user' => $this->user->getName(),
			'ua' => 'Mozilla/5.0',
			'ip' => '100.101.102.103',
			'emailVerified' => $isEmailConfirmed,
			'isBot' => $isBotUser,
			'knownIPoid' => $isKnownToIpoid,
			'knownLoginNotify' => $knownLoginNotify,
			'hasTwoFactorAuth' => $hasEnabledTwoFactorAuth,
			'forceEmailAuth' => false,
			'privilegedGroups' => [],
			'activeOnLocalWikiInLast90Days' => (bool)$activeOnLocalWikiInLast90Days,
			'countryCode' => '',
			'countryName' => '',
		];

		$logExpectation = $this->logger->expects( $this->once() )->method( 'info' );

		if ( $isBotUser ) {
			$logExpectation->with(
				'Email verification skipped for bot {user}',
				$logData + [ 'eventType' => 'emailauth-verification-skipped-bot' ]
			);
		} elseif ( $hasEnabledTwoFactorAuth ) {
			$logExpectation->with(
				'Email verification skipped for {user} with 2FA enabled',
				$logData + [ 'eventType' => 'emailauth-verification-skipped-2fa' ]
			);
		} elseif ( $knownLoginNotify === LoginNotify::USER_KNOWN ) {
			$logExpectation->with(
				'Email verification skipped for {user} with known IP or device',
				$logData + [ 'eventType' => 'emailauth-verification-skipped-known-loginnotify' ]
			);
		} elseif ( !$isKnownToIpoid ) {
			$logExpectation->with(
				'Email verification required for {user} without 2FA, unknown IP and device, good IP reputation',
				$logData + [ 'eventType' => 'emailauth-verification-required' ]
			);
		} else {
			$logExpectation->with(
				'Email verification required for {user} without 2FA, unknown IP and device, bad IP reputation',
				$logData + [ 'eventType' => 'emailauth-verification-required' ]
			);
		}

		$verificationRequired = false;
		$res = $this->hooks->onEmailAuthRequireToken(
			$this->user,
			$verificationRequired,
			$formMessage,
			$subject,
			$body,
			$bodyHtml
		);

		$this->assertTrue( $res );
		$this->assertSame(
			$shouldRequireVerification && $shouldEnforceVerification,
			$verificationRequired
		);
	}

	public static function provideVerificationCases(): iterable {
		$testCases = [
			// hasEnabledTwoFactorAuth, isEmailConfirmed, isKnownToIpoid, isBotUser,
			// shouldEnforceVerification, knownLoginNotify, activeOnLocalWikiInLast90Days
			[ false, false, false, false, false, 'LoginNotify::USER_NOT_KNOWN', null ],
			[ true, false, false, false, false, 'LoginNotify::USER_NOT_KNOWN', null ],
			[ false, true, false, false, false, 'LoginNotify::USER_NOT_KNOWN', null ],
			[ false, false, true, false, false, 'LoginNotify::USER_NOT_KNOWN', null ],
			[ false, false, false, true, false, 'LoginNotify::USER_NOT_KNOWN', null ],
			[ false, false, false, false, true, 'LoginNotify::USER_NOT_KNOWN', null ],
			[ false, false, false, false, false, 'LoginNotify::USER_KNOWN', null ],
			[ false, false, false, false, false, 'LoginNotify::USER_NO_INFO', null ],
			[ false, false, false, false, false, 'LoginNotify::USER_NOT_KNOWN', false ],
			[ false, false, false, false, false, 'LoginNotify::USER_NOT_KNOWN', true ],
		];

		foreach ( $testCases as $params ) {
			[
				$hasEnabledTwoFactorAuth,
				$isEmailConfirmed,
				$isKnownToIpoid,
				$isBotUser,
				$shouldEnforceVerification,
				$knownLoginNotify,
				$activeOnLocalWikiInLast90Days
			] = $params;

			$description = sprintf(
				'2FA %s, %s, %s, %s, $wgWikimediaEventsEmailAuthEnforce: %s, LoginNotify status: %s, %s',
				$hasEnabledTwoFactorAuth ? 'enabled' : 'disabled',
				$isEmailConfirmed ? 'email confirmed' : 'email not confirmed',
				$isKnownToIpoid ? 'known to ipoid' : 'not known to ipoid',
				$isBotUser ? 'bot user' : 'not bot user',
				$shouldEnforceVerification ? 'true' : 'false',
				$knownLoginNotify,
				$activeOnLocalWikiInLast90Days ? 'active' :
					( $activeOnLocalWikiInLast90Days === null ? 'last activity unknown' : 'not active' )
			);

			yield $description => $params;
		}
	}

	public function testGetPrivilegedGroupsCallback() {
		$this->extensionRegistry->method( 'isLoaded' )
			->willReturnMap( [
				[ 'IPReputation', '*', false ],
				[ 'OATHAuth', '*', false ],
				[ 'LoginNotify', '*', false ],
				[ 'CLDR', '*', false ],
			] );
		$this->request->setCookie( 'forceEmailAuth', '1', '' );
		$this->logger->expects( $this->once() )->method( 'info' )->with(
			'Email verification skipped for {user} via test cookie',
			new ArrayHasSubset( [
				'privilegedGroups' => [],
			] )
		 );
		$verificationRequired = false;
		$this->hooks->onEmailAuthRequireToken(
			$this->user,
			$verificationRequired,
			$formMessage,
			$subject,
			$body,
			$bodyHtml
		);

		$this->setMocks( static fn () => [ 'foo', 'bar' ] );

		$this->extensionRegistry->method( 'isLoaded' )
			->willReturnMap( [
				[ 'IPReputation', '*', false ],
				[ 'OATHAuth', '*', false ],
				[ 'LoginNotify', '*', false ],
				[ 'CLDR', '*', false ],
			] );
		$this->request->setCookie( 'forceEmailAuth', '1', '' );
		$this->logger->expects( $this->once() )->method( 'info' )->with(
			'Email verification skipped for {user} via test cookie',
			new ArrayHasSubset( [
				'privilegedGroups' => [ 'foo' => 1, 'bar' => 1 ],
			] )
		);
		$verificationRequired = false;
		$this->hooks->onEmailAuthRequireToken(
			$this->user,
			$verificationRequired,
			$formMessage,
			$subject,
			$body,
			$bodyHtml
		);
	}

	public function testCountry() {
		$this->extensionRegistry->method( 'isLoaded' )
			->willReturnMap( [
				[ 'IPReputation', '*', false ],
				[ 'OATHAuth', '*', false ],
				[ 'LoginNotify', '*', false ],
				[ 'CLDR', '*', true ],
			] );
		$this->request->setCookie( 'forceEmailAuth', '1', '' );
		$this->request->setCookie( 'GeoIP', 'HU:100:Budapest:40.0:40.0:v4', '' );
		$this->countryCodeLookup->expects( $this->never() )->method( 'getFromGeoIP' );
		$this->logger->expects( $this->once() )->method( 'info' )->with(
			'Email verification skipped for {user} via test cookie',
			new ArrayHasSubset( [
				'countryCode' => 'HU',
				'countryName' => 'Hungary',
			] )
		);
		$verificationRequired = false;
		$this->hooks->onEmailAuthRequireToken(
			$this->user,
			$verificationRequired,
			$formMessage,
			$subject,
			$body,
			$bodyHtml
		);

		$this->setMocks();
		$this->extensionRegistry->method( 'isLoaded' )
			->willReturnMap( [
				[ 'IPReputation', '*', false ],
				[ 'OATHAuth', '*', false ],
				[ 'LoginNotify', '*', false ],
				[ 'CLDR', '*', true ],
			] );
		$this->request->setCookie( 'forceEmailAuth', '1', '' );
		$this->request->setIP( '100.101.102.103' );
		$this->countryCodeLookup->expects( $this->once() )->method( 'getFromGeoIP' )->willReturn( 'HU' );
		$this->logger->expects( $this->once() )->method( 'info' )->with(
			'Email verification skipped for {user} via test cookie',
			new ArrayHasSubset( [
				'countryCode' => 'HU',
				'countryName' => 'Hungary',
			] )
		);
		$verificationRequired = false;
		$this->hooks->onEmailAuthRequireToken(
			$this->user,
			$verificationRequired,
			$formMessage,
			$subject,
			$body,
			$bodyHtml
		);
	}
}
