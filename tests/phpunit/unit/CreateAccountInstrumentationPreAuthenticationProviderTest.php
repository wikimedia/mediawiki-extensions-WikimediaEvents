<?php
namespace WikimediaEvents\Tests\Unit;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthManager;
use MediaWikiUnitTestCase;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationAuthenticationRequest;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationClient;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationPreAuthenticationProvider;

/**
 * @covers \WikimediaEvents\CreateAccount\CreateAccountInstrumentationPreAuthenticationProvider
 */
class CreateAccountInstrumentationPreAuthenticationProviderTest extends MediaWikiUnitTestCase {
	/**
	 * @dataProvider provideExpectedReqs
	 * @param string $action
	 * @param AuthenticationRequest[] $expectedReqs
	 */
	public function testShouldReturnAuthenticationRequests( string $action, array $expectedReqs ): void {
		$provider = new CreateAccountInstrumentationPreAuthenticationProvider(
			$this->createNoOpMock( CreateAccountInstrumentationClient::class )
		);

		$reqs = $provider->getAuthenticationRequests( $action, [] );

		$this->assertEquals( $expectedReqs, $reqs );
	}

	public static function provideExpectedReqs(): iterable {
		yield 'not an account creation' => [ AuthManager::ACTION_LOGIN, [] ];
		yield 'account creation' => [
			AuthManager::ACTION_CREATE,
			[ new CreateAccountInstrumentationAuthenticationRequest() ]
		];
	}

}
