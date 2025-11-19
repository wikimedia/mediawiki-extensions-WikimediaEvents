<?php

namespace WikimediaEvents;

use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CentralAuth\SharedDomainUtils;
use MediaWiki\Extension\EventLogging\EventLogging;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReference;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;
use MediaWiki\WikiMap\WikiMap;

class AccountCreationLogger {

	private const SCHEMA_VERSIONED = '/analytics/mediawiki/accountcreation/account_conversion/1.1.0';
	private const STREAM_REGISTER = 'mediawiki.accountcreation.account_conversion';
	private const STREAM_LOGIN = 'mediawiki.accountcreation.login';
	private SpecialPageFactory $specialPageFactory;
	/**
	 * @var UserIdentityUtils
	 */
	private UserIdentityUtils $userIdentityUtils;

	/**
	 * @var array Mapping of special page titles to their corresponding event streams.
	 */
	private $pageStreamMap = [
		'CreateAccount' => self::STREAM_REGISTER,
		'Userlogin' => self::STREAM_LOGIN,
	];

	public function __construct( UserIdentityUtils $userIdentityUtils, SpecialPageFactory $specialPageFactory ) {
		$this->userIdentityUtils = $userIdentityUtils;
		$this->specialPageFactory = $specialPageFactory;
	}

	/**
	 * Handles logging of authentication and account creation events.
	 *
	 * @param string $stream The stream event.
	 * @param string $eventType The event type ('success' or 'failure').
	 * @param UserIdentity $performer The user involved in the event.
	 * @param AuthenticationResponse $response The authentication response.
	 */
	public function logAuthEvent(
		string $stream, string $eventType, UserIdentity $performer, AuthenticationResponse $response ): void {
		$additionalData = [];
		$title = RequestContext::getMain()->getTitle();
		if ( $title !== null ) {
			$additionalData += [
				'page_title' => $title->getDBkey(),
				'page_namespace' => $title->getNamespace(),
			];
		}
		if ( $response->status === AuthenticationResponse::FAIL ) {
			$additionalData['error_message_key'] = $response->message->getKey();
		}
		$this->doLogEvent( $stream, $eventType, $performer, $additionalData );
	}

	/**
	 * Logs different types of events (e.g., impressions, successes, failures)
	 * related to account creation and user login.
	 *
	 * @param string $stream The stream event.
	 * @param string $eventType The type of event (e.g., 'impression', 'success', 'failure').
	 * @param UserIdentity $user The user associated with the event.
	 */
	private function doLogEvent(
		string $stream,
		string $eventType,
		UserIdentity $user,
		array $additionalData
	): void {
		$sul3Enabled = false;
		if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			/** @var SharedDomainUtils $sharedDomainUtils */
			$sharedDomainUtils = MediaWikiServices::getInstance()
				->getService( 'CentralAuth.SharedDomainUtils' );
			$sul3Enabled = $sharedDomainUtils->isSul3Enabled( RequestContext::getMain()->getRequest() );
		}

		$eventData = [
			'$schema' => self::SCHEMA_VERSIONED,
			'event_type' => $eventType,
			'performer' => [
				'user_id' => $user->getId(),
				'user_text' => $user->getName(),
				'is_temp' => $this->userIdentityUtils->isTemp( $user )
			],
			'source_wiki' => WikiMap::getCurrentWikiId(),
			'sul3_enabled' => $sul3Enabled,
		];
		$eventData = array_merge( $eventData, $additionalData );
		$this->submitEvent( $stream, $eventData );
	}

	/**
	 * Submits the event data to the EventLogging service.
	 *
	 * @param string $stream The event stream.
	 * @param array $eventData The prepared event data.
	 */
	private function submitEvent( string $stream, array $eventData ): void {
		EventLogging::submit( $stream, $eventData );
	}

	/**
	 * Logs login event with a specified event type.
	 * @param string $eventType The type of the login event (e.g., 'success', 'failure').
	 * @param UserIdentity $performer The user who is performing the login action.
	 * @param AuthenticationResponse $response The response received from the authentication process
	 */
	public function logLoginEvent(
		string $eventType, UserIdentity $performer, AuthenticationResponse $response ): void {
		$this->logAuthEvent( self::STREAM_LOGIN, $eventType, $performer, $response );
	}

	/**
	 * Logs account creation event with a specified event type.
	 * @param string $eventType The type of the account creation event (e.g., 'success', 'failure').
	 * @param UserIdentity $performer The user who is attempting to
	 *        create a new account or the user performing the action.
	 * @param AuthenticationResponse $response The response from the account creation process.
	 */
	public function logAccountCreationEvent(
		string $eventType, UserIdentity $performer, AuthenticationResponse $response ): void {
		$this->logAuthEvent( self::STREAM_REGISTER, $eventType, $performer, $response );
	}

	/**
	 * Logs page impression events for specified pages
	 * like account creation or login page.
	 *
	 * @param PageReference $pageReference
	 * @param UserIdentity $performer The user viewing the page.
	 */
	public function logPageImpression(
		PageReference $pageReference,
		UserIdentity $performer,
		WebRequest $request
	): void {
		if ( $request->wasPosted() && $request->getCheck( 'authAction' ) ) {
			// This is not a first impression but a subsequent step; don't log
			return;
		}

		$stream = $this->determineStreamForPage( $pageReference );

		if ( !$stream ) {
			// Not an instrumented page, do nothing.
			return;
		}

		$additionalData = [
			'page_title' => $pageReference->getDBkey(),
			'page_namespace' => $pageReference->getNamespace(),
		];
		$this->doLogEvent( $stream, 'impression', $performer, $additionalData );
	}

	/**
	 * Determines the appropriate event stream based on the page title.
	 *
	 * @param PageReference $pageReference Reference to the special page.
	 * @return string|null The stream constant or null if the page is not instrumented.
	 */
	private function determineStreamForPage( PageReference $pageReference ): ?string {
		[ $canonicalName, ] = $this->specialPageFactory->resolveAlias( $pageReference->getDBkey() );
		return $this->pageStreamMap[$canonicalName] ?? null;
	}

}
