<?php
namespace WikimediaEvents\CreateAccount;

use MediaWiki\Auth\AbstractPreAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Context\RequestContext;
use StatusValue;

/**
 * A pre-authentication provider that adds a custom hidden field to Special:CreateAccount
 * for instrumenting form submissions from non-JS clients.
 */
class CreateAccountInstrumentationPreAuthenticationProvider extends AbstractPreAuthenticationProvider {
	private CreateAccountInstrumentationClient $client;

	public function __construct( CreateAccountInstrumentationClient $client ) {
		$this->client = $client;
	}

	/** @inheritDoc */
	public function getAuthenticationRequests( $action, array $options ): array {
		if ( $action === AuthManager::ACTION_CREATE ) {
			return [ new CreateAccountInstrumentationAuthenticationRequest() ];
		}

		return [];
	}

	/** @inheritDoc */
	public function testForAccountCreation( $user, $creator, array $reqs ): StatusValue {
		$req = AuthenticationRequest::getRequestByClass(
			$reqs,
			CreateAccountInstrumentationAuthenticationRequest::class
		);

		// Emit an interaction event if a non-JS submission is detected.
		if ( $req ) {
			$this->client->submitInteraction(
				RequestContext::getMain(),
				'submit',
				[ 'action_context' => $user->getName() ]
			);
		}

		return StatusValue::newGood();
	}
}
