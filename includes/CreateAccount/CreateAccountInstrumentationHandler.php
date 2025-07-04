<?php
namespace WikimediaEvents\CreateAccount;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\Hook\AuthChangeFormFieldsHook;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;

class CreateAccountInstrumentationHandler implements SpecialPageBeforeExecuteHook, AuthChangeFormFieldsHook {

	private ExtensionRegistry $extensionRegistry;

	public function __construct( ExtensionRegistry $extensionRegistry, ) {
		$this->extensionRegistry = $extensionRegistry;
	}

	/**
	 * Add instrumentation module to Special:CreateAccount (T394744).
	 * @inheritDoc
	 */
	public function onSpecialPageBeforeExecute( $special, $subPage ): void {
		if ( $special->getName() === 'CreateAccount' &&
			$this->extensionRegistry->isLoaded( 'EventLogging' ) ) {
			$special->getOutput()->addModules( 'ext.wikimediaEvents.createAccount' );
		}
	}

	/**
	 * Render a hidden form field on Special:CreateAccount for non-JS clients only
	 * to instrument form submissions from non-JS clients (T394744).
	 * @inheritDoc
	 */
	public function onAuthChangeFormFields( $requests, $fieldInfo, &$formDescriptor, $action ): void {
		// Modify the hidden field used to instrument non-JS account creation submissions
		// to actually wrap itself in a <noscript> tag.
		if ( $action === AuthManager::ACTION_CREATE &&
			isset( $formDescriptor[CreateAccountInstrumentationAuthenticationRequest::NAME] ) ) {
			$formDescriptor[CreateAccountInstrumentationAuthenticationRequest::NAME]['class'] =
				HTMLNoScriptHiddenField::class;
		}
	}
}
