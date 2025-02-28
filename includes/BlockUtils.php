<?php

namespace WikimediaEvents;

use MediaWiki\Block\Block;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\EventLogging\EventLogging;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class BlockUtils {
	/**
	 * Log a blocked edit attempt
	 *
	 * @param User $user
	 * @param Title $title
	 * @param string $interface
	 * @param string $platform
	 */
	public static function logBlockedEditAttempt( $user, $title, $interface, $platform ) {
		$block = $user->getBlock();
		if ( !$block ) {
			return;
		}

		// Prefer the local block over the global one if both are set (instanceof CompositeBlock).
		// This is somewhat arbitrary, and may not always be correct for other kinds of multi-blocks.
		// (Keep in sync with account creation block logging in PermissionStatusAudit hook handler.)
		$local = !( $block instanceof \MediaWiki\Extension\GlobalBlocking\GlobalBlock );

		$rawExpiry = $block->getExpiry();
		if ( wfIsInfinity( $rawExpiry ) ) {
			$expiry = 'infinity';
		} else {
			$expiry = wfTimestamp( TS_ISO_8601, $rawExpiry );
		}

		$request = RequestContext::getMain()->getRequest();
		// Avoid accessing the service and its dependencies if we can by checking
		// first if we can get the country code from the GeoIP cookie.
		$countryCode = WikimediaEventsCountryCodeLookup::getFromCookie( $request );
		if ( !$countryCode ) {
			/** @var WikimediaEventsCountryCodeLookup $countryCodeLookup */
			$countryCodeLookup = MediaWikiServices::getInstance()->get( 'WikimediaEventsCountryCodeLookup' );
			$countryCode = $countryCodeLookup->getFromGeoIP( $request );
		}

		$event = [
			'$schema' => '/analytics/mediawiki/editattemptsblocked/1.0.0',
			'block_id' => json_encode( $block->getIdentifier() ),
			// @phan-suppress-next-line PhanTypeMismatchDimFetchNullable
			'block_type' => Block::BLOCK_TYPES[ $block->getType() ] ?? 'other',
			'block_expiry' => $expiry,
			'block_scope' => $local ? 'local' : 'global',
			'platform' => $platform,
			'interface' => $interface,
			'country_code' => WikimediaEventsCountryCodeLookup::getCountryCodeFormattedForEvent( $countryCode ),
			// http.client_ip is handled by eventgate-wikimedia
			'database' => MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::DBname ),
			'page_id' => $title->getId(),
			'page_namespace' => $title->getNamespace(),
			'rev_id' => $title->getLatestRevID(),
			'performer' => [
				'user_id' => $user->getId(),
				'user_edit_count' => $user->getEditCount() ?: 0,
			],
		];
		EventLogging::submit( 'mediawiki.editattempt_block', $event );
	}

}
