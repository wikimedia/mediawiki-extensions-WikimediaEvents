<?php
namespace WikimediaEvents\Tests\Unit;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Extension\TestKitchen\Sdk\InstrumentManagerInterface;
use MediaWikiUnitTestCase;
use WikimediaEvents\UserLogin\UserLoginInstrumentationAuthenticationRequest;
use WikimediaEvents\UserLogin\UserLoginInstrumentationPreAuthenticationProvider;

/**
 * @covers \WikimediaEvents\UserLogin\UserLoginInstrumentationPreAuthenticationProvider
 */
class UserLoginInstrumentationPreAuthenticationProviderTest extends MediaWikiUnitTestCase {
	/**
	 * @dataProvider provideExpectedReqs
	 * @param string $action
	 * @param AuthenticationRequest[] $expectedReqs
	 */
	public function testShouldReturnAuthenticationRequests( string $action, array $expectedReqs ): void {
		$provider = new UserLoginInstrumentationPreAuthenticationProvider(
			$this->createNoOpMock( InstrumentManagerInterface::class )
		);

		$reqs = $provider->getAuthenticationRequests( $action, [] );

		$this->assertEquals( $expectedReqs, $reqs );
	}

	public static function provideExpectedReqs(): iterable {
		yield 'not a login' => [ AuthManager::ACTION_CREATE, [] ];
		yield 'login' => [
			AuthManager::ACTION_LOGIN,
			[ new UserLoginInstrumentationAuthenticationRequest() ]
		];
	}
}
