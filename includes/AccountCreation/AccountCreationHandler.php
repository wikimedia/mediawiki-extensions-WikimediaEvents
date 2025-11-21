<?php
namespace WikimediaEvents\AccountCreation;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\Hook\AuthManagerLoginAuthenticateAuditHook;
use MediaWiki\Extension\EmailAuth\EmailAuthAuthenticationRequest;
use MediaWiki\Extension\OATHAuth\Auth\TwoFactorModuleSelectAuthenticationRequest;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\Hook\AuthChangeFormFieldsHook;

class AccountCreationHandler implements
	AuthChangeFormFieldsHook,
	AuthManagerLoginAuthenticateAuditHook,
	BeforePageDisplayHook
{

	/** @var AuthenticationRequest[]|null */
	private ?array $firstAuthFormRequests = null;

	public function __construct(
		private AccountCreationLogger $accountCreationLogger,
		private ExtensionRegistry $extensionRegistry,
		private AuthManager $authManager
	) {
	}

	/** @inheritDoc */
	public function onAuthManagerLoginAuthenticateAudit( $response, $user, $username, $extraData ) {
		$eventType = $response->status === AuthenticationResponse::PASS ? 'success' : 'failure';
		$additionalData = [];

		if ( $this->firstAuthFormRequests !== null ) {
			// Determine if 2FA was used
			$switchRequest = AuthenticationRequest::getRequestByClass( $this->firstAuthFormRequests,
				TwoFactorModuleSelectAuthenticationRequest::class );
			if ( $switchRequest ) {
				// Add data about 2FA types
				$additionalData += $this->getMfaData( $switchRequest );
			}
		}

		'@phan-var array{performer:\MediaWiki\User\User} $extraData';
		$this->accountCreationLogger->logLoginEvent( $eventType, $extraData[ 'performer' ]->getUser(),
			$response, $additionalData );
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$this->accountCreationLogger->logPageImpression(
			$out->getTitle(),
			$out->getRequest()->getSession()->getUser(),
			$out->getRequest()
		);
	}

	/** @inheritDoc */
	public function onAuthChangeFormFields( $requests, $fieldInfo, &$formDescriptor, $action ) {
		// This hook is called by AuthManager when the login form is built. For intermediate stages
		// in the login process, this hook is called twice: first to validate the submission of the
		// previously displayed form, and then to display the new form. We use this hook to instrument
		// 2FA and EmailAuth challenges.
		//
		// For an initial challenge, the sequence of calls is:
		// 1. Validate the username+password form
		// 2. Build the challenge form. $requests will contain a TwoFactorModuleSelectAuthenticationRequest
		//    with currentModule set to the module that issued the challenge, or an EmailAuthAuthenticationRequest.
		// In this case we want to ignore #1 and log #2.
		//
		// For a subsequent challenge (either because the user failed the challenge, or because they
		// switched to a different 2FA method), the sequence of calls is:
		// 1. Validate the challenge form; $requests will contain a TwoFactorModuleSelectAuthenticationRequest
		//    with currentModule set to the previous module, and newModule set to the new module (if switching),
		//    or an EmailAuthAuthenticationRequest.
		// 2. Build the new challenge form; $requests will contain a TwoFactorModuleSelectAuthenticationRequest
		//    with currentModule set to the new module and newModule unset, or an EmailAuthAuthenticationRequest.
		// In this case we want to log the information from #1.
		//
		// For a successful challenge, the sequence of calls is:
		// 1. Validate the challenge form; $requests will contain a TwoFactorModuleSelectAuthenticationRequest
		//    with currentModule set to the module that was used, or an EmailAuthAuthenticationRequest.
		// (No second call.)
		// In this case we don't want to log anything ourselves, because
		// onAuthManagerLoginAuthenticateAudit will be called and will log the successful login;
		// but we do want to capture 2FA information so that it can be included in the logged event.
		//
		// To handle all of these scenarios, the first call to this hook just stashes the relevant
		// parameters and otherwise does nothing. The second call then examines the parameters
		// to both calls and decides what to do. For a succesful login, there won't be a second
		// call, but that's OK because we want to do nothing in that case.
		if ( $this->firstAuthFormRequests === null ) {
			// This is the first call, just stash the requests
			$this->firstAuthFormRequests = $requests;
			return;
		}

		// This is the second call. Figure out what's happening and log it.
		$user = $this->authManager->getRequest()->getSession()->getUser();

		if ( $this->extensionRegistry->isLoaded( 'EmailAuth' ) ) {
			$emailAuthRequest = AuthenticationRequest::getRequestByClass( $requests,
				EmailAuthAuthenticationRequest::class );
			if ( $emailAuthRequest ) {
				// It doesn't matter whether this was an initial challenge or a subsequent challenge,
				// we log the same data in both cases.
				$this->accountCreationLogger->logLoginEvent( 'emailauth-challenge', $user, null );
				return;
			}
		}

		if ( $this->extensionRegistry->isLoaded( 'OATHAuth' ) ) {
			// In theory, it's possible for $requests to contain a 2FA challenge request but no
			// TwoFactorModuleSelectAuthenticationRequest. However, this only happens if the user has
			// only one 2FA type available, and in practice that is not possible, because every user
			// who has 2FA has the 'recoverycodes' module and at least one other module. So we don't
			// handle this case here, and we assume that there will always be a
			// TwoFactorModuleSelectAuthenticationRequest.
			// Use the one from the first call if there is one, otherwise the one from the second call.
			$oldestSwitchRequest =
				AuthenticationRequest::getRequestByClass( $this->firstAuthFormRequests,
					TwoFactorModuleSelectAuthenticationRequest::class ) ??
				AuthenticationRequest::getRequestByClass( $requests,
					TwoFactorModuleSelectAuthenticationRequest::class );
			if ( !$oldestSwitchRequest ) {
				// 2FA was not used
				return;
			}

			$mfaData = $this->getMfaData( $oldestSwitchRequest );
			$eventType = isset( $mfaData['mfa_type_switched_from'] ) ? '2fa-switch' : '2fa-challenge';
			$this->accountCreationLogger->logLoginEvent( $eventType, $user, null, $mfaData );
		}
	}

	/**
	 * Extract instrumentation data about 2FA from a switch request.
	 *
	 * @param TwoFactorModuleSelectAuthenticationRequest $request The earliest instance of
	 *   TwoFactorModuleSelectAuthenticationRequest that we saw.
	 * @return array{mfa_type: string, mfa_types_offered: string[], mfa_type_switched_from?: string}
	 */
	private function getMfaData( TwoFactorModuleSelectAuthenticationRequest $request ) {
		// Use an intermediate variable to make Phan happy, otherwise it doesn't understand that
		// $request->newModule can't be null below the if statement
		$newModule = $request->newModule;
		if ( $newModule === null ) {
			// The user didn't switch modules
			return [
				'mfa_type' => $request->currentModule,
				'mfa_types_offered' => array_keys( $request->allowedModules )
			];
		}

		// The user switched modules
		return [
			'mfa_type' => $newModule,
			'mfa_types_offered' => array_keys( $request->allowedModules ),
			'mfa_type_switched_from' => $request->currentModule
		];
	}
}
