<?php

/**
 * Service Wirings for WikimediaEvents extension.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @since 1.39
 */

use GeoIp2\Database\Reader;
use MaxMind\Db\Reader\InvalidDatabaseException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use WikimediaEvents\AccountCreationLogger;
use WikimediaEvents\Services\WikimediaEventsRequestDetailsLookup;
use WikimediaEvents\WebABTest\WebABTestArticleIdFactory;
use WikimediaEvents\WikimediaEventsCountryCodeLookup;

return [
	'WikimediaEvents.WebABTestArticleIdFactory' => static function (): WebABTestArticleIdFactory {
		return new WebABTestArticleIdFactory();
	},
	'WikimediaEventsCountryCodeLookup' => static function (
		MediaWikiServices $mediaWikiServices
	): WikimediaEventsCountryCodeLookup {
		$reader = null;
		try {
			$wmeGeoIp2Path = $mediaWikiServices->getMainConfig()->get( 'WMEGeoIP2Path' );
			if ( $wmeGeoIp2Path ) {
				$reader = new Reader( $wmeGeoIp2Path );
			}
		} catch ( InvalidDatabaseException | InvalidArgumentException $e ) {
			LoggerFactory::getInstance( 'WikimediaEvents' )
				->error( $e->getMessage() );
		}
		return new WikimediaEventsCountryCodeLookup( $reader );
	},
	'AccountCreationLogger' => static function ( MediaWikiServices $services ): AccountCreationLogger {
		return new AccountCreationLogger( $services->getUserIdentityUtils(), $services->getSpecialPageFactory() );
	},
	'WikimediaEventsRequestDetailsLookup' => static function (): WikimediaEventsRequestDetailsLookup {
		return new WikimediaEventsRequestDetailsLookup();
	},
];
