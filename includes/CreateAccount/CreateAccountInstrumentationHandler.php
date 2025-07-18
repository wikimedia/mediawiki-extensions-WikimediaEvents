<?php
namespace WikimediaEvents\CreateAccount;

use Config;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\Hook\AuthChangeFormFieldsHook;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use MediaWiki\User\Registration\UserRegistrationLookup;
use MediaWiki\Utils\UrlUtils;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class CreateAccountInstrumentationHandler implements
	SpecialPageBeforeExecuteHook,
	AuthChangeFormFieldsHook,
	BeforePageDisplayHook
{
	/**
	 * Only record a post-registration pageview event for users not older than this value.
	 */
	private const USER_AGE_INSTRUMENTATION_CUTOFF_SECONDS = 86400;

	public function __construct(
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly UserRegistrationLookup $userRegistrationLookup,
		private readonly CreateAccountInstrumentationClient $client,
		private readonly UrlUtils $urlUtils,
		private readonly Config $config
	) {
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

	/**
	 * Send an interaction event for pageviews by a newly registered user (T394744).
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$this->config->get( 'WikimediaEventsCreateAccountInstrumentation' ) ) {
			return;
		}

		$user = $out->getUser();
		$registration = $this->userRegistrationLookup->getFirstRegistration( $user );
		$registration = wfTimestampOrNull( TS_UNIX, $registration ) ?? 0;
		if ( ConvertibleTimestamp::time() - $registration > self::USER_AGE_INSTRUMENTATION_CUTOFF_SECONDS ) {
			return;
		}

		$request = $out->getRequest();

		// Ignore pageviews that came from the shared authentication domain
		// to avoid treating the automatic returnto redirect from CreateAccount as a real PV.
		$referer = $request->getHeader( 'Referer' ) ?: '';
		$refererUrl = $this->urlUtils->parse( $referer );
		if ( isset( $refererUrl['host'] ) && $this->config->has( 'CentralAuthLoginWiki' ) ) {
			$loginWikiId = $this->config->get( 'CentralAuthLoginWiki' );
			if ( $loginWikiId ) {
				$loginWiki = WikiMap::getWiki( $loginWikiId );
				$loginWikiUrl = $this->urlUtils->parse( $loginWiki?->getCanonicalServer() ?? '' );
				if ( isset( $loginWikiUrl['host'] ) && $loginWikiUrl['host'] === $refererUrl['host'] ) {
					return;
				}
			}
		}

		$this->client->submitInteraction( $out, 'pageview', [ 'action_context' => $user->getName() ] );
	}
}
