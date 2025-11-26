<?php

namespace WikimediaEvents;

use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Extension\IPReputation\IPoid\IPoidResponse;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Hooks for logging IP reputation data with an event (edit, account creation, etc.)
 *
 * Note: these hook implementations will eventually move to Extension:IPReputation, when
 * that is running in production.
 */
class IPReputationHooks implements PageSaveCompleteHook, LocalUserCreatedHook {

	private const STREAM = 'mediawiki.ip_reputation.score';
	private const SCHEMA = '/analytics/mediawiki/ip_reputation/score/1.4.0';

	private Config $config;
	private UserFactory $userFactory;
	private UserGroupManager $userGroupManager;
	private EventSubmitter $eventSubmitter;
	private CentralIdLookup $centralIdLookup;
	/**
	 * Callable that returns the entry point for this event as defined in MW_ENTRY_POINT.
	 * Useful for testing.
	 * @var callable
	 */
	private $entryPointProvider;
	private ?IPReputationIPoidDataLookup $ipReputationIPoidDataLookup;

	public function __construct(
		Config $config,
		UserFactory $userFactory,
		UserGroupManager $userGroupManager,
		EventSubmitter $eventSubmitter,
		CentralIdLookup $centralIdLookup,
		?IPReputationIPoidDataLookup $ipReputationIPoidDataLookup = null,
		?callable $entryPointProvider = null
	) {
		$this->config = $config;
		$this->userFactory = $userFactory;
		$this->userGroupManager = $userGroupManager;
		$this->eventSubmitter = $eventSubmitter;
		$this->centralIdLookup = $centralIdLookup;
		$this->ipReputationIPoidDataLookup = $ipReputationIPoidDataLookup;
		$this->entryPointProvider = $entryPointProvider ?? static fn (): string => MW_ENTRY_POINT;
	}

	/** @inheritDoc */
	public function onLocalUserCreated( $user, $autocreated ) {
		$ip = RequestContext::getMain()->getRequest()->getIP();
		DeferredUpdates::addCallableUpdate( function () use ( $ip, $user, $autocreated ) {
			$action = $autocreated ? 'autocreateaccount' : 'createaccount';
			$this->recordEvent( $ip, $action, $user, $user->getId() );
		} );
	}

	/** @inheritDoc */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$ip = RequestContext::getMain()->getRequest()->getIP();
		DeferredUpdates::addCallableUpdate( function () use (
			$ip,
			$user,
			$revisionRecord
		) {
			$userObject = $this->userFactory->newFromUserIdentity( $user );
			if ( !$this->shouldLogEditEventForUser( $userObject ) ) {
				return;
			}
			$this->recordEvent( $ip, 'edit', $user, $revisionRecord->getId() );
		} );
	}

	/**
	 * Check if we should log an edit event for a user.
	 *
	 * The main thing is to exclude logged-in accounts over a certain account age
	 * threshold (the default is 90 days).
	 *
	 * @param User $user
	 * @return bool True if we should log, false otherwise.
	 */
	private function shouldLogEditEventForUser( User $user ): bool {
		$userRegistration = $user->getRegistration();
		if ( !$user->isAnon() && !$userRegistration ) {
			// The user account is not anonymous and there's no registration date, so
			// it is a very old account. Don't do record anything for these accounts.
			return false;
		}
		$userAge = ConvertibleTimestamp::time() - (int)wfTimestampOrNull( TS_UNIX, $userRegistration );
		$threshold = $this->config->get( 'WikimediaEventsIPReputationAccountAgeThreshold' );
		return $userAge <= $threshold * ExpirationAwareness::TTL_DAY;
	}

	/**
	 * @param IPoidResponse $IPoidResponse Data returned from IPoid service
	 * @return array Array of data suitable for use with ip_reputation.score stream
	 */
	private function convertIPoidDataToEventLoggingFormat( IPoidResponse $IPoidResponse ): array {
		$event = [
			'risks' => $IPoidResponse->getRisks(),
			'client_proxies' => $IPoidResponse->getProxies(),
			'organization' => $IPoidResponse->getOrganization(),
			'client_count' => $IPoidResponse->getNumUsersOnThisIP(),
			'client_types' => $IPoidResponse->getConnectionTypes(),
			'location_city' => $IPoidResponse->getCity(),
			'location_country' => $IPoidResponse->getCountry(),
			'client_countries' => $IPoidResponse->getCountries(),
			'client_behaviors' => $IPoidResponse->getBehaviors(),
			'tunnels_operators' => $IPoidResponse->getTunnelOperators(),
		];

		return array_filter( $event, static fn ( $val ) => $val !== null );
	}

	/**
	 * Attempt to fetch data from ipoid, and submit an appropriate event if data is found.
	 *
	 * @param string $ip
	 * @param string $action
	 * @param UserIdentity $user
	 * @param int $identifier
	 * @return void
	 */
	private function recordEvent( string $ip, string $action, UserIdentity $user, int $identifier ) {
		if ( !$this->ipReputationIPoidDataLookup ) {
			return;
		}
		$data = $this->ipReputationIPoidDataLookup->getIPoidDataForIp( $ip, __METHOD__ );
		if ( !$data ) {
			return;
		}
		$event = $this->convertIPoidDataToEventLoggingFormat( $data );
		$userEntitySerializer = new UserEntitySerializer(
			$this->userFactory, $this->userGroupManager, $this->centralIdLookup
		);
		$event += [
			'$schema' => self::SCHEMA,
			'wiki_id' => WikiMap::getCurrentWikiId(),
			'http' => [ 'client_ip' => $ip ],
			'performer' => $userEntitySerializer->toArray( $user ),
			'action' => $action,
			'mw_entry_point' => ( $this->entryPointProvider )(),
			'identifier' => $identifier,
		];
		$this->eventSubmitter->submit( self::STREAM, $event );
	}
}
