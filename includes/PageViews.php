<?php

namespace WikimediaEvents;

use CentralAuthUser;
use ContextSource;
use DeferredUpdates;
use EventLogging;
use ExtensionRegistry;
use GrowthExperiments\HelpPanel;
use IContextSource;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MobileContext;
use MWCryptHash;
use MWCryptRand;
use RequestContext;
use Title;
use User;

class PageViews extends ContextSource {

	/**
	 * Used to define the maximum age of the user accounts we are interested in logging
	 * for.
	 */
	const DAY_LIMIT_IN_SECONDS = 86400;

	/**
	 * Used to define the maximum age of the user accounts for which we want to log visits to
	 * to the help desk.
	 */
	const HELP_DESK_DAY_LIMIT_IN_SECONDS = 1209600;

	/**
	 * Constants mapping to the keys in the PageViews schema.
	 */
	const EVENT_TITLE = 'title';
	const EVENT_PAGE_ID = 'page_id';
	const EVENT_REQUEST_METHOD = 'request_method';
	const EVENT_ACTION = 'action';
	const EVENT_PERMISSION_ERRORS = 'permission_errors';
	const EVENT_HTTP_RESPONSE_CODE = 'http_response_code';
	const EVENT_IS_MOBILE = 'is_mobile';
	const EVENT_NAMESPACE = 'namespace';
	const EVENT_PATH = 'path';
	const EVENT_QUERY = 'query';
	const EVENT_USER_ID = 'user_id';
	const REDACT_STRING = 'redacted';

	/**
	 * @var array
	 */
	private $event;

	/**
	 * @var null|string
	 */
	private $action;

	/**
	 * @var Title|null
	 */
	private $originalTitle;

	/**
	 * @var int
	 */
	private $originalUserId = 0;

	/**
	 * @var User
	 */
	private $user;

	/**
	 * PageViews constructor.
	 * @param IContextSource $context
	 */
	public function __construct( IContextSource $context ) {
		$this->setContext( $context );
		$this->action = $context->getRequest()->getVal( 'action', 'view' );
		$this->originalTitle = $context->getTitle();
		$this->originalUserId = $context->getUser()->getId();
		$this->event = [];
	}

	/**
	 * Convenience function to log page views via a deferred update.
	 * @param int $userId
	 */
	public static function deferredLog( $userId = 0 ) {
		// The purpose of logging POST events in presend stage is to ensure correct chronological
		// order of events in the eventlogging database.
		// The timestamp on the eventlogging capsule is set when the deferred update executes,
		// and it's important that the POST event is logged before the BeforePageDisplay hook
		// fires for the subsequent GET request.
		$stage = RequestContext::getMain()->getRequest()->wasPosted() ? DeferredUpdates::PRESEND :
			DeferredUpdates::POSTSEND;
		DeferredUpdates::addCallableUpdate( function () use ( $userId ) {
			$pageViews = new PageViews( RequestContext::getMain() );
			$pageViews->setOriginalUserId( $userId );
			$pageViews->log();
		}, $stage );
	}

	/**
	 * Set the title to the relevant title.
	 *
	 * This allows us to obfuscate visits to e.g. /wiki/Special:MovePage/PageName, in addition to
	 * handling the basic case of /wiki/PageName.
	 *
	 * @return null|Title
	 */
	public function getTitle() {
		return $this->getSkin()->getRelevantTitle();
	}

	/**
	 * @param User $user
	 */
	public function setUser( User $user ) {
		$this->user = $user;
	}

	/**
	 * @return User
	 */
	public function getUser() {
		// No need to calculate this multiple times per request.
		if ( $this->user instanceof User ) {
			return $this->user;
		}
		// Use default implementation.
		$this->user = parent::getUser();
		// Override if originalUserId is set (in case of user logout).
		if ( $this->originalUserId ) {
			$this->user = User::newFromId( $this->originalUserId );
		}
		return $this->user;
	}

	/**
	 * Query parameters with potentially sensitive information.
	 */
	private function getSensitiveQueryParams() {
		return [
			'search' => 'hash',
			'return' => 'hash',
			'returnto' => 'hash',
			'title' => 'redact',
			'create' => 'redact',
			'token' => 'redact',
		];
	}

	/**
	 * Get permission errors for the event action as a comma-separated list.
	 *
	 * @return string
	 */
	public function getPermissionErrors() {
		// For now, we only care about permission errors on edit attempts unless the output page
		// title is an exact match for the permissionserrors string.
		if ( $this->action !== 'edit' &&
			 // This is intended to match the logic from OutputPage::setPageTitle(), as called
			 // via ErrorPageError::report()
			 $this->getOutput()->getPageTitle() !== $this->getOutput()->msg( 'permissionserrors' )->text()
		) {
			return '';
		}
		$permissionErrors = $this->getTitle()->getUserPermissionsErrors(
			$this->action, $this->getUser()
		);
		if ( !$permissionErrors ) {
			return '';
		}
		$flattenedErrors = array_map( function ( $error ) {
			return implode( ',', $error );
		}, $permissionErrors );

		return implode( ',', $flattenedErrors );
	}

	/**
	 * Log page views data.
	 *
	 * @return bool
	 * @throws \ConfigException
	 */
	public function log() {
		if ( !$this->userIsInCohort() ) {
			return false;
		}
		$parts = wfParseUrl( $this->getRequest()->getFullRequestURL() );

		$event = [
			self::EVENT_TITLE => $this->getTitle()->getText(),
			self::EVENT_PAGE_ID => (string)$this->getTitle()->getArticleID(),
			self::EVENT_REQUEST_METHOD => $this->getRequest()->getMethod(),
			self::EVENT_ACTION => $this->action,
			self::EVENT_PERMISSION_ERRORS => $this->getPermissionErrors(),
			self::EVENT_HTTP_RESPONSE_CODE => http_response_code(),
			self::EVENT_IS_MOBILE => class_exists( 'MobileContext' )
				&& MobileContext::singleton()->shouldDisplayMobileView(),
			self::EVENT_NAMESPACE => $this->getTitle()->getNamespace(),
			self::EVENT_PATH => $parts['path'],
			self::EVENT_QUERY => $parts['query'] ?? '',
			self::EVENT_USER_ID => $this->getUser()->getId()
		];
		$this->setEvent( $event );
		$this->redactSensitiveData();

		// Reset namespace and page ID for cases where the original title is a Special page
		// but the relevant one is not.
		if ( $this->originalTitle->isSpecialPage() ) {
			$event = $this->getEvent();
			$event[self::EVENT_NAMESPACE] = $this->originalTitle->getNamespace();
			$event[self::EVENT_PAGE_ID] = (string)$this->originalTitle->getArticleID();
			$this->setEvent( $event );
		}

		return EventLogging::logEvent( 'EditorJourney', 18910134, $this->getEvent() );
	}

	/**
	 * @return array
	 */
	public function getEvent() {
		return $this->event;
	}

	/**
	 * @param array $event
	 */
	public function setEvent( $event ) {
		$this->event = $event;
	}

	/**
	 * Scrub out sensitive data for various namespaces, excluding Main_Page.
	 */
	public function redactSensitiveData() {
		// Hash sensitive query parameters.
		$this->hashSensitiveQueryParams();

		$eventToModify = $this->getEvent();
		$titleFromRequest = null;
		$titlePropertyFromRequest = $this->getRequest()->getVal( 'title' );
		if ( $titlePropertyFromRequest ) {
			$titleFromRequest = Title::newFromText( $titlePropertyFromRequest );
		}

		// Check if the title namespace is in the list of sensitive namespaces.
		if ( !in_array( $eventToModify[self::EVENT_NAMESPACE], $this->getSensitiveNamespaces() ) ) {
			if ( !$titleFromRequest ) {
				// Title isn't set in request, and our event's title is not in a sensitive
				// namespace, so return early.
				return;
			}

			// Check if request title matches the context title.
			if ( $titleFromRequest instanceof Title
				 && !$titleFromRequest->equals( $this->getTitle() )
				 && in_array( $titleFromRequest->getNamespace(), $this->getSensitiveNamespaces() ) ) {
				// Scrub the path and query for the title from request, then return.
				$eventToModify[self::EVENT_PATH] = str_replace(
					[
						$titlePropertyFromRequest,
						wfUrlencode( $titleFromRequest->getDBkey() )
					],
					$this->hash( $titleFromRequest->getDBkey() ),
					$eventToModify[self::EVENT_PATH]
				);
				$eventToModify[self::EVENT_QUERY] = str_replace(
					[
						$titlePropertyFromRequest,
						wfUrlencode( $titleFromRequest->getDBkey() )
					],
					$this->hash( $titleFromRequest->getDBkey() ),
					$eventToModify[self::EVENT_QUERY]
				);
				$this->setEvent( $eventToModify );
				return;
			}

			// None of the checks above passed, and we're not in a sensitive namespace, so
			// return.
			return;
		}

		// If Main_Page, don't obfuscate any details.
		if ( $this->getTitle()->isMainPage() ) {
			return;
		}

		// Otherwise, scrub sensitive info.
		if ( (int)$eventToModify[self::EVENT_PAGE_ID] !== 0 ) {
			$eventToModify[self::EVENT_PAGE_ID] = $this->hash( $eventToModify[self::EVENT_PAGE_ID] );
		}

		// Replace instances of title in the path.
		$eventToModify[self::EVENT_PATH] = str_replace(
			[
				$this->getTitle()->getDBkey(),
				wfUrlencode( $this->getTitle()->getDBkey() )
			],
			$this->hash( $this->getTitle()->getDBkey() ),
			$eventToModify[self::EVENT_PATH]
		);
		// Sanitize any other matches for title in the query.
		$eventToModify[self::EVENT_QUERY] = str_replace(
			[
				$this->getTitle()->getDBkey(),
				wfUrlencode( $this->getTitle()->getDBkey() )
			],
			$this->hash( $this->getTitle()->getDBkey() ),
			$eventToModify[self::EVENT_QUERY]
		);

		$eventToModify[self::EVENT_TITLE] = $this->hash( $eventToModify[self::EVENT_TITLE] );
		$this->setEvent( $eventToModify );
	}

	/**
	 * @param string $data
	 *   The data to be hashed.
	 * @return string
	 *   The hashed data.
	 */
	public function hash( $data ) {
		return MWCryptHash::hmac( (string)$data, $this->getHashingSalt(), false );
	}

	/**
	 * Get the user derived hashing salt lookup key.
	 *
	 * @return string
	 *   The lookup key in a format that can be passed to $cache->get().
	 */
	public function getUserHashingSaltLookupKey() {
		// Generate a lookup key for storing the salt, unique per user across all sessions.
		$userLookupKey = MWCryptHash::hash(
			$this->getUser()->getId() . $this->getUser()->getRegistration(),
			false
		);
		$cache = MediaWikiServices::getInstance()->getMainObjectStash();
		return $cache->makeKey( 'editor-journey', $userLookupKey );
	}

	/**
	 * Generate a random salt for the user and place in stash, using user-derived key.
	 *
	 * @return string
	 *   The hash salt generated for the user.
	 */
	public function setUserHashingSalt() {
		$userSalt = MWCryptRand::generateHex( 64 );
		$cache = MediaWikiServices::getInstance()->getMainObjectStash();
		$cache->set(
			$this->getUserHashingSaltLookupKey(),
			$userSalt,
			$cache::TTL_DAY,
			$cache::WRITE_SYNC
		);
		return $userSalt;
	}

	/**
	 * Retrieve the hash salt for the user, using a user derived lookup key.
	 *
	 * @return string
	 *   The secret key to use for the HMAC.
	 */
	private function getHashingSalt() {
		static $userSalt;
		// No need to read this from cache multiple times per request.
		if ( $userSalt ) {
			return $userSalt;
		}

		$cache = MediaWikiServices::getInstance()->getMainObjectStash();
		$userSalt = $cache->get( $this->getUserHashingSaltLookupKey() );
		if ( !$userSalt ) {
			LoggerFactory::getInstance( 'WikimediaEvents' )->error(
				'Retrieving hash salt for user ID {user_id} failed, generating a new one.',
				[
					'user_id' => $this->getUser()->getId()
				]
			);
			$userSalt = $this->setUserHashingSalt();
		}
		return $userSalt;
	}

	/**
	 * Check if user is in cohort.
	 *
	 * @return bool
	 * @throws \ConfigException
	 */
	public function userIsInCohort() {
		$user = $this->getUser();

		// If registration property isn't set, assume it's an older user without this property.
		if ( $user->isAnon() || $user->getRegistration() === null ) {
			return false;
		}

		$accountAge = wfTimestamp() - wfTimestamp( TS_UNIX, $user->getRegistration() );
		if ( $accountAge >= $this->getAccountAgeLimit() ) {
			return false;
		}

		// Exclude autocreated accounts. We can't directly tell if an account
		// was autocreated, but we can look at its home wiki.
		if ( class_exists( 'CentralAuthUser' ) ) {
			$globalUser = CentralAuthUser::getInstance( $user );
			$homeWiki = $globalUser->getHomeWiki();
			return $homeWiki === wfWikiID() || $homeWiki === null;
		}
		return true;
	}

	/**
	 * @return bool
	 * @throws \ConfigException
	 */
	private function isHelpPanelAndHelpDeskConfigured() {
		$config = $this->getConfig();
		return ExtensionRegistry::getInstance()->isLoaded( 'GrowthExperiments' ) &&
			$config->get( 'GEHelpPanelEnabled' ) &&
			$config->get( 'GEHelpPanelHelpDeskTitle' ) &&
			HelpPanel::getHelpDeskTitle( $config )->isValid();
	}

	/**
	 * Check if the user is visiting the wiki's configured help desk.
	 *
	 * Note that, on kowiki, the help desk title is configured as a template that expands to a
	 * monthly archive format, e.g. "Help_Desk/January_2019". This method returns true
	 * if the user is visiting "Help_Desk/January_2019" and also when the user visits "Help_Desk".
	 *
	 * @return bool
	 */
	private function isHelpDeskVisit() {
		$helpDeskTitle = HelpPanel::getHelpDeskTitle( $this->getConfig() );
		return $this->originalTitle->equals( $helpDeskTitle ) ||
			$this->getTitle()->equals( $helpDeskTitle ) ||
			$this->originalTitle->isSubpageOf( $helpDeskTitle ) ||
			$this->getTitle()->isSubpageOf( $helpDeskTitle ) ||
			$helpDeskTitle->isSubpageOf( $this->originalTitle ) ||
			$helpDeskTitle->isSubpageOf( $this->getTitle() );
	}

	/**
	 * Get the limit, in seconds, to compare against the account age.
	 *
	 * If GrowthExperiments isn't present, the help panel isn't enabled, or the help desk isn't
	 * defined in configuration, then just track 24 hours of visits.
	 *
	 * If it's a help desk or subpage of help desk visit, modify the account age limit to 14 days.
	 *
	 * @return int
	 * @throws \ConfigException
	 */
	public function getAccountAgeLimit() {
		return ( $this->isHelpPanelAndHelpDeskConfigured() &&
			$this->isHelpDeskVisit() ) ?
			self::HELP_DESK_DAY_LIMIT_IN_SECONDS :
			self::DAY_LIMIT_IN_SECONDS;
	}

	/**
	 * Namespaces for which we will scrub out page title / ID.
	 *
	 * @return array
	 */
	public function getSensitiveNamespaces() {
		return $this->getConfig()->get( 'WMEUnderstandingFirstDaySensitiveNamespaces' );
	}

	/**
	 * Hash or redact a query parameter if set.
	 *
	 * @param string $queryParam
	 * @param string $operation
	 *   Should be one of 'hash' or 'redact'.
	 */
	public function hashQueryParamIfSet( $queryParam, $operation = 'hash' ) {
		$eventToModify = $this->getEvent();
		if ( isset( $eventToModify[self::EVENT_QUERY] ) && $eventToModify[self::EVENT_QUERY] ) {
			$query = wfCgiToArray( $eventToModify[self::EVENT_QUERY] );
			if ( isset( $query[ $queryParam ] ) && $query ) {
				$query[ $queryParam ] = $operation === 'hash' ?
					$this->hash( $query[ $queryParam ] ) : self::REDACT_STRING;
				$eventToModify[self::EVENT_QUERY] = wfArrayToCgi( $query );
			}
		}
		$this->setEvent( $eventToModify );
	}

	/**
	 * Hash sensitive query parameters.
	 */
	public function hashSensitiveQueryParams() {
		foreach ( $this->getSensitiveQueryParams() as $param => $op ) {
			$this->hashQueryParamIfSet( $param, $op );
		}
	}

	/**
	 * Setter for originalUserId.
	 *
	 * @param int $userId
	 */
	public function setOriginalUserId( $userId ) {
		$this->originalUserId = $userId;
	}

}
