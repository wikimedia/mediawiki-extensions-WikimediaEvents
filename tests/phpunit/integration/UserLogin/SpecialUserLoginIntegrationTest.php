<?php
namespace WikimediaEvents\Tests\Integration\UserLogin;

use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Specials\SpecialPageTestBase;
use WikimediaEvents\UserLogin\UserLoginInstrumentationAuthenticationRequest;
use WikimediaEvents\UserLogin\UserLoginInstrumentationPreAuthenticationProvider;

/**
 * @covers \WikimediaEvents\UserLogin\UserLoginInstrumentationHandler::onAuthChangeFormFields
 * @covers \WikimediaEvents\UserLogin\UserLoginInstrumentationAuthenticationRequest
 * @covers \WikimediaEvents\UserLogin\UserLoginInstrumentationPreAuthenticationProvider
 */
class SpecialUserLoginIntegrationTest extends SpecialPageTestBase {
	protected function newSpecialPage() {
		return $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'Userlogin' );
	}

	public function testShouldAddHiddenNoScriptFieldForInstrumentation(): void {
		$authConfig = $this->getConfVar( MainConfigNames::AuthManagerConfig );

		$this->overrideConfigValue(
			MainConfigNames::AuthManagerConfig,
			array_merge(
				$authConfig,
				[
					'preauth' => [
						'UserLoginInstrumentationPreAuthenticationProvider' => [
							'class' => UserLoginInstrumentationPreAuthenticationProvider::class,
							'services' => [ 'TestKitchen.InstrumentManager' ],
							'sort' => 2
						],
					]
				]
			)
		);

		[ $html ] = $this->executeSpecialPage();

		$name = UserLoginInstrumentationAuthenticationRequest::NAME;

		$this->assertStringContainsString(
			"<noscript><input type=\"hidden\" value=\"1\" name=\"$name\"></noscript>",
			$html
		);
	}
}
