<?php

namespace WikimediaEvents\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\OAuth\SessionProvider as OAuthSessionProvider;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Session\Session;
use MediaWiki\Session\SessionProvider;
use MediaWiki\Skin\Skin;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use MockTitleTrait;
use Wikimedia\Stats\StatsFactory;
use Wikimedia\Stats\StatsUtils;
use Wikimedia\Stats\UnitTestingHelper;
use Wikimedia\TestingAccessWrapper;
use WikimediaEvents\WikimediaEventsHooks;

/**
 * @covers \WikimediaEvents\WikimediaEventsHooks
 * @group Database
 */
class WikimediaEventsHooksTest extends \MediaWikiIntegrationTestCase {

	use MockTitleTrait;
	use TempUserTestTrait;

	/**
	 * @dataProvider provideStatsFactoryOnPageSaveComplete
	 */
	public function testStatsFactoryOnPageSaveComplete(
		string $userAgent, string $userType, string $skinName, array $expectedStats
	) {
		$context = RequestContext::getMain();
		$context->getRequest()->setHeader( 'User-agent', $userAgent );
		$skin = $this->createMock( Skin::class );
		$skin->method( 'getSkinName' )->willReturn( $skinName );
		$context->setSkin( $skin );
		TestingAccessWrapper::newFromObject( $context->getTiming() )->entries = [
			'requestStart' => [ 'entryType' => 'mark', 'startTime' => 1.900, 'duration' => 0, ],
			'requestShutdown' => [ 'entryType' => 'mark', 'startTime' => 2.023, 'duration' => 0, ]
		];
		if ( $userType === 'anon' ) {
			$this->disableAutoCreateTempUser();
			$authority = $this->getServiceContainer()->getUserFactory()->newAnonymous();
		} elseif ( $userType === 'named' ) {
			$this->enableAutoCreateTempUser();
			$authority = $this->getTestUser()->getAuthority();
		} elseif ( $userType === 'temp' ) {
			$this->enableAutoCreateTempUser();
			$authority = $this->getServiceContainer()->getTempUserCreator()->create(
				'~2024-1', new FauxRequest()
			)->getUser();
		}
		$statsHelper = StatsFactory::newUnitTestingHelper();
		$this->setService( 'StatsFactory', $statsHelper->getStatsFactory() );

		$this->editPage( 'Test', 'Test', '', NS_MAIN, $authority );
		$this->assertStatsEmitted( $statsHelper, $expectedStats );
	}

	/**
	 * Asserts that the expected stats were emitted.
	 *
	 * Processes the expected stats, replacing the {{wiki}} placeholder with the current wiki ID. This must be done
	 * during the test as the wiki ID is modified during {@link \MediaWikiIntegrationTestCase::setupAllTestDBs()}, which
	 * executes after the {@link WikimediaEventsHooksTest::provideStatsFactoryOnPageSaveComplete()} data provider is
	 * executed.
	 *
	 * @param UnitTestingHelper $unitTestingHelper
	 * @param array $expectedStats
	 */
	private function assertStatsEmitted( UnitTestingHelper $unitTestingHelper, array $expectedStats ): void {
		$wiki = StatsUtils::normalizeString( WikiMap::getCurrentWikiId() );
		$expectedStats = array_map(
			static function ( $stat ) use ( $wiki ) {
				return str_replace( '{{wiki}}', $wiki, $stat );
			},
			$expectedStats
		);

		$this->assertArrayContains( $expectedStats, $unitTestingHelper->consumeAllFormatted() );
	}

	public static function provideStatsFactoryOnPageSaveComplete(): array {
		return [
			[
				'Commons/0.0 (https://mediawiki.org/wiki/Apps/Commons) Android/0',
				'named',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:{{wiki}},user:normal,is_mobile:1',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:normal,entry:other',
				]
			],
			[
				'WikipediaApp/0.0 (iOS)',
				'named',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:{{wiki}},user:normal,is_mobile:1',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:normal,entry:other',
				]
			],
			[
				'WikipediaApp/0.0 (iOS)',
				'temp',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:{{wiki}},user:temp,is_mobile:1',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:temp,entry:other',
				]
			],
			[
				'WikipediaApp/0.0 (Android)',
				'temp',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:{{wiki}},user:temp,is_mobile:1',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:temp,entry:other',
				]
			],
			'Unknown platform' => [
				'Firefox/0.0',
				'temp',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:{{wiki}},user:temp,is_mobile:0',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:temp,entry:other',
				]
			],
			'VisualEditor temp account on mobile' => [
				'Firefox/0.0',
				'temp',
				'minerva',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:{{wiki}},user:temp,is_mobile:1',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:temp,entry:other',
				]
			],
			'VisualEditor anon on desktop' => [
				'Firefox/0.0',
				'anon',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:{{wiki}},user:anon,is_mobile:0',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:anon,entry:other',
				]
			],
		];
	}

	public function testOnXAnalyticsSetHeader() {
		$getMockOutputPage = function ( $title, $request, $user ): OutputPage {
			$out = $this->createNoOpMock( OutputPage::class, [ 'getTitle', 'getRequest', 'getUser', 'getRevisionId' ] );
			$out->method( 'getTitle' )->willReturn( $title );
			$out->method( 'getRequest' )->willReturn( $request );
			$out->method( 'getUser' )->willReturn( $user );
			$out->method( 'getRevisionId' )->willReturn( 1000 );
			return $out;
		};

		$handler = new WikimediaEventsHooks(
			$this->getServiceContainer()->getMainConfig(),
			$this->getServiceContainer()->getNamespaceInfo(),
			$this->getServiceContainer()->getPermissionManager(),
			$this->getServiceContainer()->get( 'WikimediaEventsRequestDetailsLookup' )
		);

		$title = $this->makeMockTitle( 'Foo', [
			'id' => 123,
			'namespace' => NS_HELP,
		] );
		$user = new User();
		$sessionProvider = $this->createNoOpAbstractMock( SessionProvider::class, [ '__toString' ] );
		$session = $this->createNoOpMock( Session::class, [ 'getUser', 'getProvider', 'getSessionId' ] );
		$session->method( 'getUser' )->willReturnCallback( static function () use ( &$user ) {
			return $user;
		} );
		$session->method( 'getProvider' )->willReturn( $sessionProvider );
		$request = new FauxRequest( [], false, $session );

		$out = $getMockOutputPage( $title, $request, $user );
		$headerItems = [];
		$handler->onXAnalyticsSetHeader( $out, $headerItems );
		$this->assertSame( NS_HELP, $headerItems['ns'] );
		$this->assertSame( 123, $headerItems['page_id'] );
		$this->assertArrayNotHasKey( 'special', $headerItems );
		$this->assertSame( 1000, $headerItems['rev_id'] );
		$this->assertArrayNotHasKey( 'loggedIn', $headerItems );
		$this->assertArrayNotHasKey( 'auth_type', $headerItems );

		$title = $this->makeMockTitle( 'UserLogin', [
			'namespace' => NS_SPECIAL,
			'id' => 0,
		] );
		$out = $getMockOutputPage( $title, $request, $user );
		$headerItems = [];
		$handler->onXAnalyticsSetHeader( $out, $headerItems );
		$this->assertArrayNotHasKey( 'page_id', $headerItems );
		$this->assertSame( 'Userlogin', $headerItems['special'] );

		$user = $this->getTestUser()->getUser();
		$out = $getMockOutputPage( $title, $request, $user );
		$headerItems = [];
		$handler->onXAnalyticsSetHeader( $out, $headerItems );
		$this->assertSame( 1, $headerItems['loggedIn'] );
		$this->assertSame( 'unknown-' . get_class( $sessionProvider ), $headerItems['auth_type'] );

		if ( !class_exists( OAuthSessionProvider::class ) ) {
			// Horrible hack to avoid pulling in OAuth just for mocking an instanceof check.
			require_once __DIR__ . '/../../../.phan/stubs/SessionProvider.php';
		}
		$providerMetadata = [ 'oauthVersion' => 1, 'consumerId' => 42 ];
		$sessionProvider = $this->createNoOpAbstractMock( OAuthSessionProvider::class, [ '__toString' ] );
		$session = $this->createNoOpMock( Session::class,
			[ 'getUser', 'getProvider', 'getSessionId', 'getProviderMetadata' ] );
		$session->method( 'getUser' )->willReturnCallback( static function () use ( &$user ) {
			return $user;
		} );
		$session->method( 'getProvider' )->willReturn( $sessionProvider );
		$session->method( 'getProviderMetadata' )->willReturnCallback( static function () use ( &$providerMetadata ) {
			return $providerMetadata;
		} );
		$request = new FauxRequest( [], false, $session );

		$out = $getMockOutputPage( $title, $request, $user );
		$headerItems = [];
		$handler->onXAnalyticsSetHeader( $out, $headerItems );
		$this->assertSame( 'oauth1', $headerItems['auth_type'] );

		$providerMetadata = [ 'oauthVersion' => 2, 'consumerId' => null ];
		$headerItems = [];
		$handler->onXAnalyticsSetHeader( $out, $headerItems );
		$this->assertSame( 'oauth2-owneronly', $headerItems['auth_type'] );
	}
}
