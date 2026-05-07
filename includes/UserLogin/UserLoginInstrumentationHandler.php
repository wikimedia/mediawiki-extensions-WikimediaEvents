<?php
namespace WikimediaEvents\UserLogin;

use MediaWiki\Auth\AuthManager;
use MediaWiki\SpecialPage\Hook\AuthChangeFormFieldsHook;
use WikimediaEvents\CreateAccount\HTMLNoScriptHiddenField;

class UserLoginInstrumentationHandler implements AuthChangeFormFieldsHook {
	/**
	 * Wrap the noscript-instrumentation hidden field in a <noscript> tag so it
	 * is only rendered (and thus only submitted) by clients that do not run
	 * JavaScript. The pre-authentication provider uses the field's presence or
	 * absence in the submitted request set to classify the client.
	 * @inheritDoc
	 */
	public function onAuthChangeFormFields( $requests, $fieldInfo, &$formDescriptor, $action ): void {
		if ( $action === AuthManager::ACTION_LOGIN &&
			isset( $formDescriptor[UserLoginInstrumentationAuthenticationRequest::NAME] ) ) {
			$formDescriptor[UserLoginInstrumentationAuthenticationRequest::NAME]['class'] =
				HTMLNoScriptHiddenField::class;
		}
	}
}
