<?php
namespace WikimediaEvents\Tests\Integration\UserLogin;

use MediaWiki\Auth\UsernameAuthenticationRequest;
use MediaWiki\Config\HashConfig;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\TestKitchen\Sdk\InstrumentInterface;
use MediaWiki\Extension\TestKitchen\Sdk\InstrumentManagerInterface;
use MediaWiki\Tests\Unit\Auth\AuthenticationProviderTestTrait;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\UserLogin\UserLoginInstrumentationAuthenticationRequest;
use WikimediaEvents\UserLogin\UserLoginInstrumentationPreAuthenticationProvider;

/**
 * @covers \WikimediaEvents\UserLogin\UserLoginInstrumentationPreAuthenticationProvider::testForAuthentication
 */
class UserLoginInstrumentationPreAuthenticationProviderIntegrationTest extends MediaWikiIntegrationTestCase {
	use AuthenticationProviderTestTrait;

	private function setMainTitle( Title $title ): void {
		RequestContext::getMain()->setTitle( $title );
	}

	private function newProvider(
		InstrumentManagerInterface $manager,
		bool $instrumentationEnabled = true
	): UserLoginInstrumentationPreAuthenticationProvider {
		$provider = new UserLoginInstrumentationPreAuthenticationProvider( $manager );
		$this->initProvider(
			$provider,
			new HashConfig( [ 'WikimediaEventsUserLoginInstrumentation' => $instrumentationEnabled ] )
		);
		return $provider;
	}

	/**
	 * Builds an InstrumentManager mock whose getInstrument() returns the given
	 * instrument mock. Asserts it is called exactly once with the expected
	 * instrument name.
	 */
	private function newInstrumentManagerReturning( InstrumentInterface $instrument ): InstrumentManagerInterface {
		$manager = $this->createMock( InstrumentManagerInterface::class );
		$manager->expects( $this->once() )
			->method( 'getInstrument' )
			->with( 'special-user-login' )
			->willReturn( $instrument );
		return $manager;
	}

	public function testShouldEmitNoJsSubtypeWhenFieldIsPresent(): void {
		$this->setMainTitle( Title::newFromText( 'Special:UserLogin' ) );

		$instrument = $this->createMock( InstrumentInterface::class );
		$instrument->expects( $this->once() )
			->method( 'send' )
			->with(
				'submit',
				[
					'action_subtype' => 'nojs',
					'action_context' => 'TestUser',
				]
			);
		$provider = $this->newProvider( $this->newInstrumentManagerReturning( $instrument ) );

		$noscriptReq = new UserLoginInstrumentationAuthenticationRequest();
		$usernameReq = new UsernameAuthenticationRequest();
		$usernameReq->username = 'TestUser';

		$status = $provider->testForAuthentication( [ $noscriptReq, $usernameReq ] );

		$this->assertTrue( $status->isGood() );
	}

	public function testShouldEmitJsSubtypeWhenFieldIsAbsent(): void {
		$this->setMainTitle( Title::newFromText( 'Special:UserLogin' ) );

		$instrument = $this->createMock( InstrumentInterface::class );
		$instrument->expects( $this->once() )
			->method( 'send' )
			->with(
				'submit',
				[
					'action_subtype' => 'js',
					'action_context' => 'TestUser',
				]
			);
		$provider = $this->newProvider( $this->newInstrumentManagerReturning( $instrument ) );

		$usernameReq = new UsernameAuthenticationRequest();
		$usernameReq->username = 'TestUser';

		$status = $provider->testForAuthentication( [ $usernameReq ] );

		$this->assertTrue( $status->isGood() );
	}

	/**
	 * @dataProvider provideNonUserloginTitles
	 */
	public function testShouldNotEmitWhenTitleIsNotSpecialUserlogin( Title $title ): void {
		$this->setMainTitle( $title );

		$manager = $this->createMock( InstrumentManagerInterface::class );
		$manager->expects( $this->never() )->method( 'getInstrument' );
		$provider = $this->newProvider( $manager );

		$usernameReq = new UsernameAuthenticationRequest();
		$usernameReq->username = 'TestUser';

		$status = $provider->testForAuthentication( [ $usernameReq ] );

		$this->assertTrue( $status->isGood() );
	}

	public static function provideNonUserloginTitles(): iterable {
		// Login via api.php — ApiEntryPoint sets a dummy NS_SPECIAL title for the
		// duration of the request (see includes/api/ApiEntryPoint.php).
		yield 'api.php dummy title' => [
			Title::makeTitle( NS_SPECIAL, 'Badtitle/dummy title for API calls set in api.php' ),
		];
		// A different special page (e.g. opening Special:CreateAccount in a tab
		// while a login attempt is in flight from elsewhere).
		yield 'different special page' => [
			Title::makeTitle( NS_SPECIAL, 'CreateAccount' ),
		];
		// A content page (defensive: catches any login flow whose context has
		// drifted to a non-special page).
		yield 'content page' => [
			Title::makeTitle( NS_MAIN, 'SomeArticle' ),
		];
	}

	public function testShouldNotEmitWhenTitleIsNull(): void {
		// Some early-bootstrap and pre-routing contexts have no title at all;
		// the provider must short-circuit on null before attempting to inspect
		// the title.
		RequestContext::getMain()->setTitle( null );

		$manager = $this->createMock( InstrumentManagerInterface::class );
		$manager->expects( $this->never() )->method( 'getInstrument' );
		$provider = $this->newProvider( $manager );

		$usernameReq = new UsernameAuthenticationRequest();
		$usernameReq->username = 'TestUser';

		$status = $provider->testForAuthentication( [ $usernameReq ] );

		$this->assertTrue( $status->isGood() );
	}

	public function testShouldNotEmitWhenInstrumentationConfigIsDisabled(): void {
		$this->setMainTitle( Title::newFromText( 'Special:UserLogin' ) );

		$manager = $this->createMock( InstrumentManagerInterface::class );
		$manager->expects( $this->never() )->method( 'getInstrument' );
		$provider = $this->newProvider( $manager, false );

		$usernameReq = new UsernameAuthenticationRequest();
		$usernameReq->username = 'TestUser';

		$status = $provider->testForAuthentication( [ $usernameReq ] );

		$this->assertTrue( $status->isGood() );
	}
}
