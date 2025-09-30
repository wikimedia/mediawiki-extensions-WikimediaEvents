<?php
namespace WikimediaEvents\Tests\Integration;

use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\Hooks;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use RequestContext;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationAuthenticationRequest;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationClient;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationPreAuthenticationProvider;

/**
 * @covers \WikimediaEvents\CreateAccount\CreateAccountInstrumentationPreAuthenticationProvider
 */
class CreateAccountInstrumentationPreAuthenticationProviderTest extends MediaWikiIntegrationTestCase {

	public function testShouldSubmitInteractionWhenRequestForHiddenFieldIsPresent(): void {
		$user = $this->createMock( User::class );
		$user->method( 'getName' )
			->willReturn( 'TestUser' );

		$reqs = [
			new PasswordAuthenticationRequest(),
			new CreateAccountInstrumentationAuthenticationRequest()
		];

		$client = $this->createMock( CreateAccountInstrumentationClient::class );
		$callIndex = 0;
		$captcha = Hooks::getInstance( CaptchaTriggers::CREATE_ACCOUNT );
		$expectedCalls = [
			[ RequestContext::getMain(), 'submit', [ 'action_context' => $user->getName() ] ],
			[ RequestContext::getMain(), 'captcha_class_serverside', [ 'action_context' => $captcha->getName() ] ],
		];
		$client->expects( $this->exactly( 2 ) )
			->method( 'submitInteraction' )
			->willReturnCallback( function ( $context, $action, $params ) use ( &$expectedCalls, &$callIndex )  {
				$this->assertEquals( $expectedCalls[$callIndex][0], $context );
				$this->assertEquals( $expectedCalls[$callIndex][1], $action );
				$this->assertEquals( $expectedCalls[$callIndex][2], $params );
				$callIndex++;
			} );
		$provider = new CreateAccountInstrumentationPreAuthenticationProvider( $client );

		$status = $provider->testForAccountCreation(
			$user,
			$this->createNoOpMock( User::class ),
			$reqs
		);

		$this->assertStatusGood( $status );
	}
}
