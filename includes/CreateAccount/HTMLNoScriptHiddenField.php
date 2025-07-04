<?php
namespace WikimediaEvents\CreateAccount;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLFormField;

/**
 * A hidden HTMLForm field that is wrapped inside a <noscript> tag
 * so that it is conditionally rendered for non-JS clients only.
 */
class HTMLNoScriptHiddenField extends HTMLFormField {
	/** @inheritDoc */
	public function getInputHTML( $value ): string {
		// Ignore $value in favor of a static flag that can be used to detect the presence of the field.
		// Since this is a hidden field, $value would always be sourced from the descriptor default,
		// preventing us from distinguishing between submissions that include the field and those that do not.
		$hidden = Html::hidden( $this->mName, '1' );

		return Html::rawElement( 'noscript', [], $hidden );
	}
}
