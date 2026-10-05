<?php

namespace WikimediaEvents\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\OAuth\SessionProvider as OAuthSessionProvider;
use MediaWiki\Extension\TestKitchen\Sdk\ExperimentInterface;
use MediaWiki\Extension\TestKitchen\Sdk\ExperimentManagerInterface;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader as RL;
use MediaWiki\Session\Session;
use MediaWiki\Session\SessionProvider;
use MediaWiki\Skin\Skin;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\Title\Title;
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

	private function newHookHandler(
		?ExperimentManagerInterface $experimentManager = null
	): WikimediaEventsHooks {
		$captchaFactory = null;
		if ( $this->getServiceContainer()->getExtensionRegistry()->isLoaded( 'ConfirmEdit' ) ) {
			$captchaFactory = $this->getServiceContainer()->get( 'ConfirmEditCaptchaFactory' );
		}
		return new WikimediaEventsHooks(
			$this->getServiceContainer()->getMainConfig(),
			$this->getServiceContainer()->getNamespaceInfo(),
			$this->getServiceContainer()->getPermissionManager(),
			$this->getServiceContainer()->get( 'WikimediaEventsRequestDetailsLookup' ),
			$experimentManager ?? $this->getServiceContainer()->get( 'TestKitchen.ExperimentManager' ),
			$captchaFactory
		);
	}

	/**
	 * @return ExperimentManagerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mockExperimentManager( bool $isAssignedEditBlockedGroup ): ExperimentManagerInterface {
		$experiment = $this->createMock( ExperimentInterface::class );
		$experiment->method( 'isAssignedGroup' )->with( 'edit-blocked' )->willReturn( $isAssignedEditBlockedGroup );

		$experimentManager = $this->createMock( ExperimentManagerInterface::class );
		$experimentManager->method( 'getExperiment' )
			->with( 'email-confirmation-enforcement-delayed' )
			->willReturn( $experiment );

		return $experimentManager;
	}

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

		$handler = $this->newHookHandler();

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

	/**
	 * @dataProvider provideDiffTracking
	 */
	public function testDiffTracking( array $requestParams, bool $isNamed, bool $expected ): void {
		$title = $this->createMock( Title::class );
		$title->method( 'isSpecial' )->willReturn( false );

		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( $isNamed );

		$addedModules = [];
		$out = $this->createMock( OutputPage::class );
		$out->method( 'addModules' )->willReturnCallback(
			static function ( $module ) use ( &$addedModules ) {
				$addedModules = array_merge( $addedModules, (array)$module );
			}
		);
		$out->method( 'getTitle' )->willReturn( $title );
		$out->method( 'getUser' )->willReturn( $user );
		$out->method( 'getRequest' )->willReturn( new FauxRequest( $requestParams ) );

		$handler = $this->newHookHandler();
		$handler->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );

		$this->assertSame(
			$expected,
			in_array( 'ext.wikimediaEvents.diff', $addedModules, true )
		);
	}

	public static function provideDiffTracking(): array {
		return [
			'diff of a specific revision' => [ [ 'diff' => '1234' ], true, true ],
			'diff=prev' => [ [ 'diff' => 'prev' ], true, true ],
			// Special:Diff redirects to these, and DifferenceEngine treats an empty diff
			// parameter as "diff against the previous revision", so it counts too.
			'empty diff parameter' => [ [ 'diff' => '' ], true, true ],
			'not a diff' => [ [], true, false ],
			'oldid alone is a permalink, not a diff' => [ [ 'oldid' => '1234' ], true, false ],
			// An anonymous or temporary account is not named (T440220).
			'diff viewed by an unnamed account' => [ [ 'diff' => '1234' ], false, false ],
		];
	}

	/**
	 * @dataProvider providePersonalDashboardFeedSources
	 */
	public function testPersonalDashboardFeedSources( array $attribute, array $expected ): void {
		// $scope is unused, but it keeps the override until the test ends, and PHP 8.5 marks
		// setAttributeForTest() #[NoDiscard] (T413223).
		$scope = ExtensionRegistry::getInstance()
			->setAttributeForTest( 'PersonalDashboardFeedSources', $attribute );

		$data = WikimediaEventsHooks::getPersonalDashboardFeedSources(
			$this->createMock( RL\Context::class ),
			$this->getServiceContainer()->getMainConfig()
		);

		$this->assertSame( [ 'sources' => $expected ], $data );
	}

	public static function providePersonalDashboardFeedSources(): array {
		// The attribute maps a source name to an ObjectFactory spec. Only the keys matter
		// here, because the origin parameter carries the name.
		$spec = [ 'class' => 'ExampleFeedSource' ];

		return [
			'the registered source names, in registration order' => [
				[ 'watchlist' => $spec, 'mostedited' => $spec ],
				[ 'watchlist', 'mostedited' ],
			],
			// Personal Dashboard is not installed, so the instrument recognises no origin.
			'no registered sources' => [ [], [] ],
		];
	}

	/**
	 * Covers both {@link WikimediaEventsHooks::onSiteNoticeAfter} and the private
	 * shouldShowEmailConfirmationDelayedBanner()
	 * @return User&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mockEligibleUser( array $overrides = [] ) {
		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( $overrides['isNamed'] ?? true );
		$user->method( 'getEmail' )->willReturn( $overrides['email'] ?? 'user@example.com' );
		$user->method( 'isEmailConfirmed' )->willReturn( $overrides['isEmailConfirmed'] ?? false );
		$user->method( 'isBot' )->willReturn( $overrides['isBot'] ?? false );
		$user->method( 'getRegistration' )->willReturn( $overrides['registration'] ?? '20260910000000' );
		return $user;
	}

	/**
	 * Bypasses EmailConfirmationHooks::isUserEligibleForEmailConfirmationExperiment() (which
	 * calls CentralAuthUser::getInstance() directly and so isn't mockable) by poking the
	 * private property that holds it, so the handler always reports the user as eligible.
	 */
	private function newAlwaysEligibleHookHandler(
		?ExperimentManagerInterface $experimentManager = null
	): WikimediaEventsHooks {
		$handler = $this->newHookHandler( $experimentManager );
		TestingAccessWrapper::newFromObject( $handler )->isUserEligibleForEmailConfirmationExperiment =
			static fn () => true;
		return $handler;
	}

	private function mockSkin( User $user, ?Title $title, ?OutputPage $out = null ): Skin {
		$skin = $this->createMock( Skin::class );
		$skin->method( 'getUser' )->willReturn( $user );
		$skin->method( 'getTitle' )->willReturn( $title );
		if ( $out ) {
			$skin->method( 'getOutput' )->willReturn( $out );
			$skin->method( 'msg' )->willReturnCallback( static fn ( ...$args ) => wfMessage( ...$args ) );
		} else {
			$skin->expects( $this->never() )->method( 'getOutput' );
		}
		return $skin;
	}

	public function testOnSiteNoticeAfterWithNoTitle(): void {
		$siteNotice = 'existing-notice';
		$this->newHookHandler()->onSiteNoticeAfter(
			$siteNotice,
			$this->mockSkin( $this->mockEligibleUser(), null )
		);

		$this->assertSame( 'existing-notice', $siteNotice );
	}

	/**
	 * @dataProvider provideIneligibleUserOverrides
	 */
	public function testOnSiteNoticeAfterWithIneligibleUser( array $overrides ): void {
		$permissionManager = $this->createMock( PermissionManager::class );
		$permissionManager->expects( $this->never() )->method( 'quickUserCan' );
		$this->setService( 'PermissionManager', $permissionManager );

		$siteNotice = 'existing-notice';
		$this->newHookHandler()->onSiteNoticeAfter(
			$siteNotice,
			$this->mockSkin( $this->mockEligibleUser( $overrides ), $this->makeMockTitle( 'Test' ) )
		);

		$this->assertSame( 'existing-notice', $siteNotice );
	}

	public static function provideIneligibleUserOverrides(): array {
		return [
			'anonymous/unregistered user' => [ [ 'isNamed' => false ] ],
			'bot user' => [ [ 'isBot' => true ] ],
			'user with confirmed email' => [ [ 'isEmailConfirmed' => true ] ],
			'user with no email' => [ [ 'email' => '' ] ],
			'user registered before the experiment started' => [ [ 'registration' => '20200101000000' ] ],
		];
	}

	public function testOnSiteNoticeAfterWithEligibleUserWhoCannotEdit(): void {
		$permissionManager = $this->createMock( PermissionManager::class );
		$permissionManager->method( 'quickUserCan' )->willReturn( false );
		$this->setService( 'PermissionManager', $permissionManager );

		$siteNotice = 'existing-notice';
		$this->newAlwaysEligibleHookHandler( $this->mockExperimentManager( true ) )->onSiteNoticeAfter(
			$siteNotice,
			$this->mockSkin( $this->createMock( User::class ), $this->makeMockTitle( 'Test' ) )
		);

		$this->assertSame( 'existing-notice', $siteNotice );
	}

	public function testOnSiteNoticeAfterWithEligibleUserWhoCanEdit(): void {
		$user = $this->createMock( User::class );
		$title = $this->makeMockTitle( 'Test' );

		$permissionManager = $this->createMock( PermissionManager::class );
		$permissionManager->expects( $this->once() )->method( 'quickUserCan' )
			->with( 'edit', $user, $title )
			->willReturn( true );
		$this->setService( 'PermissionManager', $permissionManager );

		$out = $this->createMock( OutputPage::class );
		$out->expects( $this->once() )->method( 'addModuleStyles' )
			->with( 'mediawiki.codex.messagebox.styles' );

		$siteNotice = 'existing-notice';
		$this->newAlwaysEligibleHookHandler( $this->mockExperimentManager( true ) )->onSiteNoticeAfter(
			$siteNotice,
			$this->mockSkin( $user, $title, $out )
		);

		$this->assertStringContainsString( 'existing-notice', $siteNotice );
		$this->assertStringContainsString( 'wme-email-confirmation-delayed-banner', $siteNotice );
		$this->assertStringContainsString(
			wfMessage( 'wikimediaevents-de-4-3-4-email-confirmation-experiment-banner' )->parse(),
			$siteNotice
		);
	}

}
