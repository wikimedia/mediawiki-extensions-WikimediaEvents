<?php

namespace WikimediaEvents;

use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Psr\Log\LoggerInterface;
use WANObjectCache;
use Wikimedia\IPUtils;

/**
 * Hooks for logging IP reputation data with an event (edit, account creation, etc.)
 *
 * Note: these hook implementations will eventually move to Extension:IPReputation, when
 * that is running in production.
 */
class IPReputationHooks implements PageSaveCompleteHook, LocalUserCreatedHook {

	private const STREAM = 'mediawiki.ip_reputation.score';
	private const SCHEMA = '/analytics/mediawiki/ip_reputation/score/1.0.0';

	private FormatterFactory $formatterFactory;
	private HttpRequestFactory $httpRequestFactory;
	private WANObjectCache $cache;

	private LoggerInterface $logger;
	private Config $config;
	private EventFactory $eventFactory;
	private UserFactory $userFactory;
	private UserGroupManager $userGroupManager;
	private EventSubmitter $eventSubmitter;

	public function __construct(
		Config $config,
		FormatterFactory $formatterFactory,
		HttpRequestFactory $httpRequestFactory,
		WANObjectCache $cache,
		UserFactory $userFactory,
		UserGroupManager $userGroupManager,
		EventFactory $eventFactory,
		EventSubmitter $eventSubmitter
	) {
		$this->config = $config;
		$this->formatterFactory = $formatterFactory;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->cache = $cache;
		$this->logger = LoggerFactory::getInstance( 'WikimediaEvents' );
		$this->userFactory = $userFactory;
		$this->userGroupManager = $userGroupManager;
		$this->eventFactory = $eventFactory;
		$this->eventSubmitter = $eventSubmitter;
	}

	/** @inheritDoc */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$baseUrl = $this->config->get( 'WikimediaEventsIPoidUrl' );
		if ( !$baseUrl ) {
			return false;
		}
		$ip = RequestContext::getMain()->getRequest()->getIP();
		DeferredUpdates::addCallableUpdate( function () use (
			$ip,
			$user,
			$revisionRecord
		) {
			$this->recordEvent( $ip, 'edit', $user, $revisionRecord->getId() );
		} );
	}

	/**
	 * @param string $ip
	 *
	 * @return array|null IPoid data for the specific address, or null if there is no data
	 */
	private function getIPoidDataForIp( string $ip ): ?array {
		$sanitizedIp = IPUtils::sanitizeIP( $ip );
		$data = $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'wikimediaevents-ipoid', $sanitizedIp ),
			// IPoid data is refreshed every 24 hours and roughly 10% of its IPs drop out
			// of the database each 24-hour cycle. A one hour TTL seems reasonable to allow
			// no longer problematic IPs to get evicted from the cache relatively quickly,
			// and also means that IPs for e.g. residential proxies are updated in our cache
			// relatively quickly.
			$this->cache::TTL_HOUR,
			function () use ( $sanitizedIp ) {
				// If IPoid URL isn't configured, don't do any checks, let the user proceed.
				$timeout = $this->config->get( 'WikimediaEventsIPoidRequestTimeoutSeconds' );
				// Convert IPv6 to lowercase, to match IPoid storage format.
				$url = $this->config->get( 'WikimediaEventsIPoidUrl' ) . '/feed/v1/ip/' . $sanitizedIp;
				$request = $this->httpRequestFactory->create( $url, [
					'method' => 'GET',
					'timeout' => $timeout,
					'connectTimeout' => $timeout,
				] );
				$response = $request->execute();
				if ( !$response->isOK() ) {
					// Probably a 404, which means IPoid doesn't know about the IP.
					// If not a 404, log it, so we can figure out what happened.
					if ( $request->getStatus() !== 404 ) {
						$statusFormatter = $this->formatterFactory->getStatusFormatter( RequestContext::getMain() );
						[ $errorText, $context ] = $statusFormatter->getPsr3MessageAndContext( $response );
						$this->logger->error( $errorText, $context );
					}
					return null;
				}

				$data = json_decode( $request->getContent(), true );

				if ( !$data ) {
					// Malformed data.
					$this->logger->error(
						'Got invalid JSON data while checking IP {ip}',
						[
							'ip' => $sanitizedIp,
							'response' => $request->getContent()
						]
					);
					return null;
				}

				if ( !isset( $data[$sanitizedIp] ) ) {
					// IP should always be set in the data array, but just to be safe.
					$this->logger->error(
						'Got JSON data with no IP {ip} present',
						[
							'ip' => $sanitizedIp,
							'response' => $request->getContent()
						]
					);
					return null;
				}

				// We have a match and valid data structure;
				// return the values for this IP for storage in the cache.
				return $data[$sanitizedIp];
			}
		);

		// Unlike null, false tells cache not to cache something. Normalize both to null before returning.
		if ( $data === false ) {
			return null;
		}

		return $data;
	}

	/**
	 * @param array $data Array returned from IPoid service
	 * @return array Array of data suitable for use with ip_reputation.score stream
	 */
	private function convertIPoidDataToEventLoggingFormat( array $data ): array {
		$event = [];
		// See IPoid repo's generateInsertActorQueries for mapping of ipoid fields
		// to Spur data field names.
		if ( isset( $data['risks'] ) ) {
			$event['risks'] = $data['risks'];
		}
		if ( isset( $data['proxies'] ) ) {
			$event['client_proxies'] = $data['proxies'];
		}
		if ( isset( $data['org'] ) ) {
			$event['organization'] = $data['org'];
		}
		if ( isset( $data['client_count'] ) ) {
			$event['client_count'] = $data['client_count'];
		}
		if ( isset( $data['types'] ) ) {
			$event['client_types'] = $data['types'];
		}
		if ( isset( $data['conc_city'] ) ) {
			$event['location_city'] = $data['conc_city'];
		}
		// Prefer client.concentration.country, otherwise fallback to location.country
		if ( !empty( $data['conc_country'] ) ) {
			$event['location_country'] = $data['conc_country'];
		} elseif ( isset( $data['location_country'] ) ) {
			$event['location_country'] = $data['location_country'];
		}
		if ( isset( $data['countries'] ) ) {
			$event['client_countries'] = $data['countries'];
		}
		if ( isset( $data['behaviors'] ) ) {
			$event['client_behaviors'] = $data['behaviors'];
		}
		if ( isset( $data['proxies'] ) ) {
			$event['client_proxies'] = $data['proxies'];
		}
		// IPoid's "tunnels" property is a list of tunnel operator strings.
		if ( isset( $data['tunnels'] ) ) {
			$event['tunnels_operators'] = $data['tunnels'];
		}
		// n.b. there are other properties in the ip_reputation.score stream, but
		// they rely on raw Spur data which is not currently accessible via IPoid.
		return $event;
	}

	/** @inheritDoc */
	public function onLocalUserCreated( $user, $autocreated ) {
		if ( $autocreated ) {
			return;
		}
		$ip = RequestContext::getMain()->getRequest()->getIP();
		DeferredUpdates::addCallableUpdate( function () use ( $ip, $user ) {
			$this->recordEvent( $ip, 'createaccount', $user, $user->getId() );
		} );
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
		$data = $this->getIPoidDataForIp( $ip );
		if ( !$data ) {
			return;
		}
		$event = $this->convertIPoidDataToEventLoggingFormat( $data );
		$userEntitySerializer = new UserEntitySerializer( $this->userFactory, $this->userGroupManager );
		$event += [
			'$schema' => self::SCHEMA,
			'wiki_id' => WikiMap::getCurrentWikiId(),
			'http' => [ 'client_ip' => $ip ],
			'performer' => $userEntitySerializer->toArray( $user ),
			'action' => $action,
			'identifier' => $identifier,
		];
		$this->eventSubmitter->submit( self::STREAM, $event );
	}
}
