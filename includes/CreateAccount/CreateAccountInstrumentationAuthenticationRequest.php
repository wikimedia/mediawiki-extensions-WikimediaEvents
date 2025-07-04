<?php
namespace WikimediaEvents\CreateAccount;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Language\RawMessage;

class CreateAccountInstrumentationAuthenticationRequest extends AuthenticationRequest {
	public const NAME = 'wmevents_createaccount_noscript';

	/** @inheritDoc */
	public $required = self::OPTIONAL;

	/**
	 * Needed because AuthManager populates this field with the corresponding value.
	 * @var string|null
	 */
	public ?string $wmevents_createaccount_noscript;

	public function getFieldInfo(): array {
		$emptyMsg = new RawMessage( '' );
		return [
			self::NAME => [
				// NOTE: The actual class used for this field is overridden via AuthChangeFormFields,
				// which also takes care of hiding this field.
				'type' => 'string',
				'label' => $emptyMsg,
				'help' => $emptyMsg,
			],
		];
	}
}
