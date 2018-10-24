<?php

namespace WikimediaEvents;

use ContextSource;
use EventLogging;
use IContextSource;
use MobileContext;
use Title;

class PageViews extends ContextSource {

	/**
	 * Used to define the maximum age of the user accounts we are interested in logging
	 * for.
	 */
	const DAY_LIMIT_IN_SECONDS = 86400;

	/**
	 * Constants mapping to the keys in the PageViews schema.
	 */
	const EVENT_TITLE = 'title';
	const EVENT_PAGE_TITLE = 'page_title';
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

	/**
	 * @var array
	 */
	private $event;

	/**
	 * @var null|string
	 */
	private $action;

	/**
	 * PageViews constructor.
	 * @param IContextSource $context
	 */
	public function __construct( IContextSource $context ) {
		$this->setContext( $context );
		$this->action = $context->getRequest()->getVal( 'action' );
		$this->event = [];
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
	 * Query parameters with potentially sensitive information.
	 */
	private function getSensitiveQueryParams() {
		return [
			'search',
			'return',
			'returnto',
		];
	}

	/**
	 * Get permission errors for the event action as a comma-separated list.
	 *
	 * @return string
	 */
	public function getPermissionErrors() {
		if ( !$this->action ) {
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
	 */
	public function log() {
		if ( !$this->userIsInCohort() ) {
			return false;
		}
		$parts = wfParseUrl( $this->getRequest()->getFullRequestURL() );

		$this->setEvent( [
			self::EVENT_TITLE => $this->getTitle()->getText(),
			// The context output title can differ from the above, in the event of
			// "Permission errors" when a user visits e.g. Special:Block without the relevant
			// privileges.
			self::EVENT_PAGE_TITLE => $this->getOutput()->getPageTitle(),
			self::EVENT_PAGE_ID => (string)$this->getTitle()->getArticleID(),
			self::EVENT_REQUEST_METHOD => $this->getRequest()->getMethod(),
			self::EVENT_ACTION => $this->action ?? '',
			self::EVENT_PERMISSION_ERRORS => $this->getPermissionErrors(),
			self::EVENT_HTTP_RESPONSE_CODE => http_response_code(),
			self::EVENT_IS_MOBILE => class_exists( 'MobileContext' )
				  && MobileContext::singleton()->shouldDisplayMobileView(),
			self::EVENT_NAMESPACE => $this->getTitle()->getNamespace(),
			self::EVENT_PATH => $parts['path'],
			self::EVENT_QUERY => $parts['query'] ?? '',
			self::EVENT_USER_ID => $this->getUser()->getId()
		] );
		$this->redactSensitiveData();

		return EventLogging::logEvent( 'EditorJourney', 18504997, $this->getEvent() );
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
	 * Scrub out sensitive data for various namespaces.
	 */
	public function redactSensitiveData() {
		// Hash sensitive query parameters.
		$this->hashSensitiveQueryParams();

		$eventToModify = $this->getEvent();
		// If not in a sensitive namespace, and if the relevant title is not in a sensitive
		// namespace, don't do anything further.
		if ( !in_array( $eventToModify[self::EVENT_NAMESPACE], $this->getSensitiveNamespaces() ) ) {
			return;
		}
		// Otherwise, scrub sensitive info.
		if ( (int)$eventToModify[self::EVENT_PAGE_ID] !== 0 ) {
			$eventToModify[self::EVENT_PAGE_ID] = $this->hash( $eventToModify[self::EVENT_PAGE_ID] );
		}
		// Replace instances of title in the path.
		$eventToModify[self::EVENT_PATH] = str_replace(
			$this->getTitle()->getDBkey(),
			$this->hash( $this->getTitle()->getDBkey() ),
			$eventToModify[self::EVENT_PATH]
		);
		// Sanitize any other matches for title in the query.
		$eventToModify[self::EVENT_QUERY] = str_replace(
			$this->getTitle()->getDBkey(),
			$this->hash( $this->getTitle()->getDBkey() ),
			$eventToModify[self::EVENT_QUERY]
		);

		$eventToModify[self::EVENT_TITLE] = $this->hash( $eventToModify[self::EVENT_TITLE] );
		$eventToModify[self::EVENT_PAGE_TITLE] = str_replace(
			[
				$this->getTitle()->getDBkey(),
				$this->getTitle()->getText()
			],
			$this->hash( $this->getTitle()->getDBkey() ),
			$eventToModify[self::EVENT_PAGE_TITLE]
		);
		$this->setEvent( $eventToModify );
	}

	/**
	 * @param string $data
	 *   The data to be hashed.
	 * @return string
	 *   The hashed data.
	 */
	public function hash( $data ) {
		return hash_hmac( 'md5', (string)$data, $this->getUser()->getToken() );
	}

	/**
	 * Check if user is in cohort.
	 *
	 * @return bool
	 */
	public function userIsInCohort() {
		if ( $this->getUser()->isAnon() ) {
			return false;
		}
		return ( wfTimestamp() - wfTimestamp( TS_UNIX, $this->getUser()->getRegistration() ) )
			   < self::DAY_LIMIT_IN_SECONDS;
	}

	/**
	 * Namespaces for which we will scrub out page title / ID.
	 *
	 * @return array
	 */
	public function getSensitiveNamespaces() {
		return array_filter( [
			NS_MAIN,
			NS_TALK,
			NS_FILE,
			NS_FILE_TALK,
			defined( 'NS_PORTAL' ) ? NS_PORTAL : null,
			defined( 'NS_PORTAL_TALK' ) ? NS_PORTAL_TALK : null,
			defined( 'NS_DRAFT' ) ? NS_DRAFT : null,
			defined( 'NS_DRAFT_TALK' ) ? NS_DRAFT_TALK : null,
		], function ( $value ) {
			return $value !== null;
		} );
	}

	/**
	 * Hash a query parameter if set.
	 *
	 * @param string $queryParam
	 */
	public function hashQueryParamIfSet( $queryParam ) {
		$eventToModify = $this->getEvent();
		if ( isset( $eventToModify[self::EVENT_QUERY] ) && $eventToModify[self::EVENT_QUERY] ) {
			$query = wfCgiToArray( $eventToModify[self::EVENT_QUERY] );
			if ( isset( $query[ $queryParam ] ) && $query ) {
				$query[ $queryParam ] = $this->hash( $query[ $queryParam ] );
				$eventToModify[self::EVENT_QUERY] = wfArrayToCgi( $query );
			}
		}
		$this->setEvent( $eventToModify );
	}

	/**
	 * Hash sensitive query parameters.
	 */
	public function hashSensitiveQueryParams() {
		foreach ( $this->getSensitiveQueryParams() as $param ) {
			$this->hashQueryParamIfSet( $param );
		}
	}

}
