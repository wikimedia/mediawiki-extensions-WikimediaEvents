<?php

namespace WikimediaEvents;

use MediaWiki\Block\Block;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\EventLogging\EventLogging;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class BlockUtils {
	// Possible block error keys from Block\BlockErrorFormatter::getBlockErrorMessageKey()
	public const LOCAL_ERROR_KEYS = [
		'blockedtext',
		'autoblockedtext',
		'blockedtext-partial',
		'systemblockedtext',
		'blockedtext-composite'
	];
	// Possible block error keys from GlobalBlocking extension
	public const GLOBAL_ERROR_KEYS = [
		'globalblocking-blockedtext-ip',
		'globalblocking-blockedtext-range',
		'globalblocking-blockedtext-xff',
		'globalblocking-blockedtext-user',
		'globalblocking-blockedtext-autoblock',
		'globalblocking-blockedtext-autoblock-xff',
		// WikimediaMessages versions
		'wikimedia-globalblocking-blockedtext-ip',
		'wikimedia-globalblocking-blockedtext-range',
		'wikimedia-globalblocking-blockedtext-xff',
		'wikimedia-globalblocking-blockedtext-user',
		'wikimedia-globalblocking-blockedtext-autoblock',
		'wikimedia-globalblocking-blockedtext-autoblock-xff',
	];

	/**
	 * Build error messages for error keys
	 *
	 * @param array[] $errors from PermissionManager getPermissionErrors
	 * @return array<string, Message[]>
	 */
	public static function getBlockErrorMsgs( $errors ) {
		$blockedErrorMsgs = $globalBlockedErrorMsgs = [];
		foreach ( $errors as $error ) {
			$errorMsg = Message::newFromSpecifier( $error );
			$errorKey = $errorMsg->getKey();
			if ( in_array( $errorKey, self::LOCAL_ERROR_KEYS, true ) ) {
				$blockedErrorMsgs[] = $errorMsg;
			} elseif ( in_array( $errorKey, self::GLOBAL_ERROR_KEYS, true ) ) {
				$globalBlockedErrorMsgs[] = $errorMsg;
			}
		}
		$allErrorMsgs = array_merge( $blockedErrorMsgs, $globalBlockedErrorMsgs );

		return [
			'local' => $blockedErrorMsgs,
			'global' => $globalBlockedErrorMsgs,
			'all' => $allErrorMsgs,
		];
	}

	/**
	 * Log a blocked edit attempt
	 *
	 * @param User $user
	 * @param Title $title
	 * @param string $interface
	 * @param string $platform
	 */
	public static function logBlockedEditAttempt( $user, $title, $interface, $platform ) {
		// Prefer the local block over the global one if both are set. This is
		// somewhat arbitrary, but is consistent with account creation block
		// logging.
		$local = MediaWikiServices::getInstance()->getPermissionManager()->isBlockedFrom( $user, $title, true );
		if ( $local ) {
			$block = $user->getBlock();
		} else {
			$block = $user->getGlobalBlock();
		}

		if ( !$block ) {
			return;
		}

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
