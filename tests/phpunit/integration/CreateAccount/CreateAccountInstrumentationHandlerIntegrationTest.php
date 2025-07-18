<?php
namespace WikimediaEvents\Tests\Integration\CreateAccount;

use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Site\HashSiteStore;
use MediaWiki\Site\MediaWikiSite;
use MediaWiki\User\Registration\UserRegistrationLookup;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationClient;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationHandler;

/**
 * @covers \WikimediaEvents\CreateAccount\CreateAccountInstrumentationHandler
 */
class CreateAccountInstrumentationHandlerIntegrationTest extends MediaWikiIntegrationTestCase {
	private const TEST_LOGINWIKI_ID = 'test_loginwiki';

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
			$services->getExtensionRegistry(),
			$this->userRegistrationLookup,
			$this->client,
			$services->getUrlUtils(),
			$services->getMainConfig()
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
}
