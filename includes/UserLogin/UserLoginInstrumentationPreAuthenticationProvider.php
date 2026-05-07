<?php
namespace WikimediaEvents\UserLogin;

use MediaWiki\Auth\AbstractPreAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\TestKitchen\Sdk\InstrumentManagerInterface;
use StatusValue;

/**
 * A pre-authentication provider that emits an interaction event for every
 * Special:UserLogin form submission, classifying the client as JS-enabled
 * or not based on the presence of a noscript-wrapped hidden field in the
 * submitted authentication request set.
 */
class UserLoginInstrumentationPreAuthenticationProvider extends AbstractPreAuthenticationProvider {
	/**
	 * Name of the Test Kitchen instrument that this provider sends events to.
	 * Must match the name registered in the Test Kitchen admin UI.
	 */
	private const INSTRUMENT_NAME = 'special-user-login';

	public function __construct(
		private readonly InstrumentManagerInterface $instrumentManager
	) {
	}

	/** @inheritDoc */
	public function getAuthenticationRequests( $action, array $options ): array {
		if ( $action === AuthManager::ACTION_LOGIN ) {
			return [ new UserLoginInstrumentationAuthenticationRequest() ];
		}

		return [];
	}

	/** @inheritDoc */
	public function testForAuthentication( array $reqs ): StatusValue {
		if ( !$this->config->get( 'WikimediaEventsUserLoginInstrumentation' ) ) {
			return StatusValue::newGood();
		}

		$context = RequestContext::getMain();
		$title = $context->getTitle();

		// Only instrument UI form submissions to Special:UserLogin. API and other
		// non-UI auth flows don't render the form, so the noscript field can't be
		// used to tell whether the client ran JavaScript.
		if ( !$title || !$title->isSpecial( 'Userlogin' ) ) {
			return StatusValue::newGood();
		}

		// The hidden field is wrapped in <noscript>, so its presence in $reqs implies
		// the submitting client did not run JavaScript.
		$req = AuthenticationRequest::getRequestByClass(
			$reqs,
			UserLoginInstrumentationAuthenticationRequest::class
		);
		$username = AuthenticationRequest::getUsernameFromRequests( $reqs ) ?? '';

		$this->instrumentManager
			->getInstrument( self::INSTRUMENT_NAME )
			->send(
				'submit',
				[
					'action_subtype' => $req ? 'nojs' : 'js',
					'action_context' => $username,
				]
			);

		return StatusValue::newGood();
	}
}
