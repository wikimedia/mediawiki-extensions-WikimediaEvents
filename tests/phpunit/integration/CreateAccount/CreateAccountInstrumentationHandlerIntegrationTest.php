<?php
namespace WikimediaEvents\Tests\Integration\CreateAccount;

use MediaWiki\Block\AnonIpBlockTarget;
use MediaWiki\Block\UserBlockTarget;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Site\HashSiteStore;
use MediaWiki\Site\MediaWikiSite;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\Registration\UserRegistrationLookup;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationClient;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationHandler;

/**
 * @covers \WikimediaEvents\CreateAccount\CreateAccountInstrumentationHandler
 * @group Database
 */
class CreateAccountInstrumentationHandlerIntegrationTest extends MediaWikiIntegrationTestCase {
	private const TEST_LOGINWIKI_ID = 'test_loginwiki';

	use MockAuthorityTrait;

	private UserRegistrationLookup $userRegistrationLookup;
	private CreateAccountInstrumentationClient $client;

	private CreateAccountInstrumentationHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$mockLoginWiki = new MediaWikiSite();
		$mockLoginWiki->setGlobalId( self::TEST_LOGINWIKI_ID );
		$mockLoginWiki->setPath( MediaWikiSite::PATH_PAGE, "https://loginwiki.example.com/$1" );

		$this->setService( 'SiteLookup', new HashSiteStore( [ $mockLoginWiki ] ) );

		$siteConf = $this->createMock( SiteConfiguration::class );
		$siteConf->method( 'getLocalDatabases' )
			->willReturn( [ $mockLoginWiki->getGlobalId() ] );

		$this->setMwGlobals( 'wgConf', $siteConf );

		$this->userRegistrationLookup = $this->createMock( UserRegistrationLookup::class );
		$this->client = $this->createMock( CreateAccountInstrumentationClient::class );

		$services = $this->getServiceContainer();
		$this->handler = new CreateAccountInstrumentationHandler(
			$this->userRegistrationLookup,
			$this->client,
			$services->getUrlUtils(),
			$services->getMainConfig(),
			$services->getBlockManager()
		);
	}

	/**
	 * @dataProvider provideShouldNotInstrumentPageView
	 */
	public function testShouldNotInstrumentPageview(
		bool $isInstrumentationEnabled,
		string|null|bool $userRegistrationTimestamp,
		?string $referer
	): void {
		ConvertibleTimestamp::setFakeTime( '20250103080000' );

		$this->overrideConfigValues( [
			'WikimediaEventsCreateAccountInstrumentation' => $isInstrumentationEnabled,
			'CentralAuthLoginWiki' => self::TEST_LOGINWIKI_ID,
		] );

		$user = $this->createMock( User::class );
		$user->method( 'getName' )
			->willReturn( 'TestUser' );

		$this->userRegistrationLookup->method( 'getFirstRegistration' )
			->with( $user )
			->willReturn( $userRegistrationTimestamp );

		$this->client->expects( $this->never() )
			->method( 'submitInteraction' );

		$request = new FauxRequest();
		if ( $referer ) {
			$request->setHeader( 'Referer', $referer );
		}

		$context = new RequestContext();
		$context->setUser( $user );
		$context->setRequest( $request );
		$context->setTitle( Title::makeTitle( NS_MAIN, 'Test' ) );

		$this->handler->onBeforePageDisplay( $context->getOutput(), $context->getSkin() );
	}

	public static function provideShouldNotInstrumentPageView(): iterable {
		yield 'instrumentation disabled' => [
			'isInstrumentationEnabled' => false,
			'userRegistrationTimestamp' => '20250103000000',
			'referer' => null,
		];

		yield 'too old user account' => [
			'isInstrumentationEnabled' => true,
			'userRegistrationTimestamp' => '20250101000000',
			'referer' => null,
		];

		yield 'missing registration timestamp' => [
			'isInstrumentationEnabled' => true,
			'userRegistrationTimestamp' => null,
			'referer' => null,
		];

		yield 'referer is from shared authentication domain (protocol match)' => [
			'isInstrumentationEnabled' => true,
			'userRegistrationTimestamp' => '20250103000000',
			'referer' => 'https://loginwiki.example.com/',
		];

		yield 'referer is from shared authentication domain (protocol mismatch)' => [
			'isInstrumentationEnabled' => true,
			'userRegistrationTimestamp' => '20250103000000',
			'referer' => 'http://loginwiki.example.com/',
		];
	}

	/**
	 * @dataProvider provideShouldInstrumentPageView
	 */
	public function testShouldInstrumentPageview(
		?string $referer,
		?string $loginWikiId
	): void {
		ConvertibleTimestamp::setFakeTime( '20250103080000' );

		$this->overrideConfigValues( [
			'WikimediaEventsCreateAccountInstrumentation' => true,
			'CentralAuthLoginWiki' => $loginWikiId
		] );

		$user = $this->createMock( User::class );
		$user->method( 'getName' )
			->willReturn( 'TestUser' );

		$request = new FauxRequest();
		if ( $referer ) {
			$request->setHeader( 'Referer', $referer );
		}

		$context = new RequestContext();
		$context->setUser( $user );
		$context->setRequest( $request );
		$context->setTitle( Title::makeTitle( NS_MAIN, 'Test' ) );

		$this->userRegistrationLookup->method( 'getFirstRegistration' )
			->with( $user )
			->willReturn( '20250103020000' );

		$this->client->expects( $this->once() )
			->method( 'submitInteraction' )
			->with( $context->getOutput(), 'pageview', [ 'action_context' => $user->getName() ] );

		$this->handler->onBeforePageDisplay( $context->getOutput(), $context->getSkin() );
	}

	public static function provideShouldInstrumentPageView(): iterable {
		yield 'no referer' => [
			'referer' => null,
			'loginWikiId' => self::TEST_LOGINWIKI_ID,
		];

		yield 'referer is not shared authentication domain' => [
			'referer' => 'https://some.example.com',
			'loginWikiId' => self::TEST_LOGINWIKI_ID,
		];

		yield 'no shared authentication domain configured' => [
			'referer' => 'https://loginwiki.example.com',
			'loginWikiId' => null,
		];

		yield 'invalid shared authentication domain configured' => [
			'referer' => 'https://loginwiki.example.com',
			'loginWikiId' => 'missing_wiki',
		];
	}

	/**
	 * @dataProvider provideCreateAccountBlockStatus
	 */
	public function testShouldExportCreateAccountBlockStatus(
		bool $isBlocked,
		bool $blockPreventsAccountCreation
	): void {
		$this->overrideConfigValue( 'WikimediaEventsCreateAccountInstrumentation', true );

		$request = new FauxRequest();
		$request->setIP( '1.2.3.4' );
		if ( $isBlocked ) {
			$this->getServiceContainer()->getDatabaseBlockStore()->insertBlockWithParams( [
				'address' => '1.2.3.4',
				'by' => $this->getTestSysop()->getUser(),
				'createAccount' => $blockPreventsAccountCreation,
			] );
		}

		$context = new RequestContext();
		$context->setUser( $this->getServiceContainer()->getUserFactory()->newAnonymous( '1.2.3.4' ) );
		$context->setRequest( $request );
		$context->setTitle( SpecialPage::getTitleFor( 'CreateAccount' ) );

		$this->handler->onBeforePageDisplay( $context->getOutput(), $context->getSkin() );

		$this->assertSame(
			$isBlocked && $blockPreventsAccountCreation,
			$context->getOutput()->getJsConfigVars()['wgWikimediaEventsCreateAccountIsBlocked']
		);
	}

	public static function provideCreateAccountBlockStatus(): iterable {
		yield 'not blocked' => [ false, false ];
		yield 'blocked, account creation allowed' => [ true, false ];
		yield 'blocked, account creation disabled' => [ true, true ];
	}

	/**
	 * @dataProvider provideIsNewRequest
	 */
	public function testShouldExportCreateAccountIsNewRequest(
		array $params,
		bool $wasPosted,
		bool $expected
	): void {
		$this->overrideConfigValue( 'WikimediaEventsCreateAccountInstrumentation', true );

		$context = new RequestContext();
		$context->setRequest( new FauxRequest( $params, $wasPosted ) );
		$context->setTitle( SpecialPage::getTitleFor( 'CreateAccount' ) );

		$this->handler->onBeforePageDisplay( $context->getOutput(), $context->getSkin() );

		$this->assertSame(
			$expected,
			$context->getOutput()->getJsConfigVars()['wgWikimediaEventsCreateAccountIsNewRequest']
		);
	}

	public static function provideIsNewRequest(): iterable {
		yield 'GET request' => [ [], false, true ];
		yield 'POST request' => [ [], true, false ];
		yield 'GET request with block details' => [ [ 'blockdetails' => '1' ], false, false ];
		yield 'GET request with blockdetails=0' => [ [ 'blockdetails' => '0' ], false, true ];
	}

	public function testShouldNotExportCreateAccountBlockStatusOnOtherPages(): void {
		$this->overrideConfigValue( 'WikimediaEventsCreateAccountInstrumentation', true );

		$context = new RequestContext();
		$context->setRequest( new FauxRequest() );
		$context->setTitle( Title::makeTitle( NS_MAIN, 'Test' ) );

		$this->handler->onBeforePageDisplay( $context->getOutput(), $context->getSkin() );

		$jsConfigVars = $context->getOutput()->getJsConfigVars();
		$this->assertArrayNotHasKey( 'wgWikimediaEventsCreateAccountIsBlocked', $jsConfigVars );
		$this->assertArrayNotHasKey( 'wgWikimediaEventsCreateAccountIsNewRequest', $jsConfigVars );
	}

	/** @dataProvider provideRegisteredUser */
	public function testShouldExportCreateAccountBlockStatusRegisteredUser(
		bool $ipBlocked,
		bool $userBlocked,
		array $permissions,
		bool $expectedIsBlocked,
	): void {
		$this->overrideConfigValue( 'WikimediaEventsCreateAccountInstrumentation', true );

		$request = new FauxRequest();
		$request->setIP( '1.2.3.4' );

		$context = new RequestContext();
		$context->setRequest( $request );
		$context->setTitle( SpecialPage::getTitleFor( 'CreateAccount' ) );
		$context->setAuthority( $this->mockRegisteredAuthorityWithPermissions( $permissions ) );

		if ( $ipBlocked ) {
			$this->getServiceContainer()->getDatabaseBlockStore()->insertBlockWithParams( [
				'target' => new AnonIpBlockTarget( '1.2.3.4' ),
				'by' => $this->getTestSysop()->getUser(),
				'createAccount' => true,
				'anononly' => false,
			] );
		}
		if ( $userBlocked ) {
			$this->getServiceContainer()->getDatabaseBlockStore()->insertBlockWithParams( [
				'target' => new UserBlockTarget( $context->getAuthority()->getUser() ),
				'by' => $this->getTestSysop()->getUser(),
				'createAccount' => true,
			] );
		}

		$this->handler->onBeforePageDisplay( $context->getOutput(), $context->getSkin() );

		$jsConfigVars = $context->getOutput()->getJsConfigVars();
		$this->assertSame( $expectedIsBlocked, $jsConfigVars['wgWikimediaEventsCreateAccountIsBlocked'] );
		$this->assertTrue( $jsConfigVars['wgWikimediaEventsCreateAccountIsNewRequest'] );
	}

	public static function provideRegisteredUser(): iterable {
		yield 'Nothing is blocked' => [
			'ipBlocked' => false,
			'userBlocked' => false,
			'permissions' => [],
			'expectedIsBlocked' => false,
		];
		yield 'IP-blocked' => [
			'ipBlocked' => true,
			'userBlocked' => false,
			'permissions' => [],
			'expectedIsBlocked' => true,
		];
		yield 'User-blocked' => [
			'ipBlocked' => false,
			'userBlocked' => true,
			'permissions' => [],
			'expectedIsBlocked' => true,
		];
		yield 'IP-blocked but exempt' => [
			'ipBlocked' => true,
			'userBlocked' => false,
			'permissions' => [ 'ipblock-exempt' ],
			'expectedIsBlocked' => false,
		];
		yield 'User-blocked but exempt from IP blocks' => [
			'ipBlocked' => false,
			'userBlocked' => true,
			'permissions' => [ 'ipblock-exempt' ],
			'expectedIsBlocked' => true,
		];
	}
}
