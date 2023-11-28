<?php

namespace WikimediaEvents;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use MaxMind\Db\Reader\InvalidDatabaseException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Request\WebRequest;
use Wikimedia\IPUtils;

/**
 * Lookup ISO 3166-1 alpha code for a country for an IP address, using cookie or GeoIP2
 */
class WikimediaEventsCountryCodeLookup {

	private ?Reader $reader;

	/**
	 * @param Reader|null $reader
	 */
	public function __construct( ?Reader $reader ) {
		$this->reader = $reader;
	}

	public static function getCountryCodeFormattedForEvent( ?string $countryCode ): string {
		return $countryCode ?? 'Unknown';
	}

	/**
	 * @param WebRequest $webRequest
	 * @return string|null
	 */
	public static function getFromCookie( WebRequest $webRequest ): ?string {
		$geoIpCookie = $webRequest->getCookie( 'GeoIP', '' );
		// Use the GeoIP cookie if available.
		if ( $geoIpCookie ) {
			$components = explode( ':', $geoIpCookie );
			return $components[0];
		}
		return null;
	}

	/**
	 * Attempt to obtain the country code for an IP address using GeoIP library.
	 *
	 * @param WebRequest $webRequest
	 * @return null|string
	 *   The two-character ISO 3166-1 alpha code for the country, or null if not found
	 */
	public function getFromGeoIP( WebRequest $webRequest ): ?string {
		$country = null;
		$ip = $webRequest->getIP();

		if ( $this->reader && IPUtils::isValid( $ip ) ) {
			try {
				$country = $this->reader->country( $ip )->country->isoCode;
			} catch ( InvalidDatabaseException $e ) {
				// Note, the method above can throw an exception even if instantiating
				// the reader with the database does not. See ::getRecord in Reader
				LoggerFactory::getInstance( 'WikimediaEvents' )->error( $e->getMessage() );
			} catch ( AddressNotFoundException $e ) {
				// Ignore cases where the IP isn't found.
			}
		}
		return $country ?: null;
	}

}
