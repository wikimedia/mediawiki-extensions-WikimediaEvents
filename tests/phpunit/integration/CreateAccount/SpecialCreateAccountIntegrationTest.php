<?php
namespace WikimediaEvents\Tests\Integration\CreateAccount;

use MediaWiki\MainConfigNames;
use SpecialPageTestBase;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationAuthenticationRequest;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationPreAuthenticationProvider;

/**
 * @covers \WikimediaEvents\CreateAccount\CreateAccountInstrumentationHandler::onAuthChangeFormFields
 * @covers \WikimediaEvents\CreateAccount\CreateAccountInstrumentationAuthenticationRequest
 * @covers \WikimediaEvents\CreateAccount\CreateAccountInstrumentationPreAuthenticationProvider
 */
class SpecialCreateAccountIntegrationTest extends SpecialPageTestBase {
	protected function newSpecialPage() {
		return $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'CreateAccount' );
	}

	public function testShouldAddHiddenNoScriptFieldForInstrumentation(): void {
		$authConfig = $this->getConfVar( MainConfigNames::AuthManagerConfig );

		$this->overrideConfigValue(
			MainConfigNames::AuthManagerConfig,
			array_merge(
				$authConfig,
				[
					'preauth' => [
						'CreateAccountInstrumentationPreAuthenticationProvider' => [
							'class' => CreateAccountInstrumentationPreAuthenticationProvider::class,
							'services' => [ 'WikimediaEventsCreateAccountInstrumentationClient' ],
							'sort' => 1
						],
					]
				]
			)
		);

		[ $html ] = $this->executeSpecialPage();

		$name = CreateAccountInstrumentationAuthenticationRequest::NAME;

		$this->assertStringContainsString(
			"<noscript><input name=\"$name\" type=\"hidden\" value=\"1\"></noscript>",
			$html
		);
	}
}
