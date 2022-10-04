<?php

namespace WikimediaEvents;

use ExtensionRegistry;
use FormatJson;
use MediaWiki\Extension\BetaFeatures\BetaFeatures;
use MediaWiki\Extension\EventLogging\EventLogging;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use MWTimestamp;
use RequestContext;
use RuntimeException;
use UserBucketProvider;

/**
 * Hooks and helper functions used for Wikimedia-related logging of user preference updates.
 *
 * Extracted from WikimediaEventsHooks by Sam Smith <samsmith@wikimedia.org> on 2020-02-10.
 *
 * @author Ori Livneh <ori@wikimedia.org>
 * @author Matthew Flaschen <mflaschen@wikimedia.org>
 * @author Benny Situ <bsitu@wikimedia.org>
 */
class PrefUpdateInstrumentation {

	/**
	 * @var string Bumped when the nature of the data collected in the log is changed.
	 */
	private const MAJOR_VERSION = '2';

	/**
	 * The maximum length of a property value tracked with VALUE_WELLKNOWN_SHORT.
	 *
	 * This is currently fairly liberal at 50 chars. Realistically anything even
	 * close to that is unlikely to be a non-user-generated value from a
	 * software-predefined choice. If you find values are being cropped, consider
	 * adding a dedicated aggregation type for them so that data analysts have
	 * an easier time working with the data, and to make graph-plotting easier
	 * as well.
	 *
	 * @var int
	 */
	private const SHORT_MAX_LEN = 50;

	/**
	 * Indicates that a property does only holds one of several well-known
	 * and predefined choices. By themselves these may be seen in public,
	 * and are not user-generated. Note that in relation to a user this is
	 * still considered personal information. For use in PROPERTY_TRACKLIST.
	 *
	 * @var int
	 */
	private const VALUE_WELLKNOWN_SHORT = 1;

	/**
	 * Indicates that a property holds potentially personal information formatted
	 * as a newline-separated list. The instrumentation will report the value
	 * as a count (zero or more). For use in PROPERTY_TRACKLIST.
	 *
	 * @var int
	 */
	private const VALUE_NEWLINE_COUNT = 2;

	/**
	 * Indicates that a property is a beta feature that managed by the
	 * BetaFeatures extension. For use in PROPERTY_TRACKLIST.
	 *
	 * @var int
	 */
	private const VALUE_BETA_FEATURE = 3;

	/**
	 * @var string[] List of preferences (aka user properties, aka user options)
	 * to track via EventLogging when they are changed (T249894)
	 */
	private const PROPERTY_TRACKLIST = [
		// Reading Web team
		'skin' => self::VALUE_WELLKNOWN_SHORT,
		'mfMode' => self::VALUE_WELLKNOWN_SHORT,
		'mf_amc_optin' => self::VALUE_WELLKNOWN_SHORT,
		'VectorSkinVersion' => self::VALUE_WELLKNOWN_SHORT,

		// Editing team
		'discussiontools-betaenable' => self::VALUE_BETA_FEATURE,
		'betafeatures-auto-enroll'  => self::VALUE_WELLKNOWN_SHORT,
		'discussiontools-topicsubscription' => self::VALUE_WELLKNOWN_SHORT,
		'discussiontools-autotopicsub' => self::VALUE_WELLKNOWN_SHORT,

		// AHT
		'echo-notifications-blacklist' => self::VALUE_NEWLINE_COUNT,
		'email-blacklist' => self::VALUE_NEWLINE_COUNT,

		// Growth team
		'growthexperiments-help-panel-tog-help-panel' => self::VALUE_WELLKNOWN_SHORT,
		'growthexperiments-homepage-enable' => self::VALUE_WELLKNOWN_SHORT,
		'growthexperiments-homepage-pt-link' => self::VALUE_WELLKNOWN_SHORT,
		'growthexperiments-mentorship-weight' => self::VALUE_WELLKNOWN_SHORT,
		'growthexperiments-mentor-away-timestamp' => self::VALUE_WELLKNOWN_SHORT,
		'growthexperiments-homepage-mentorship-enabled' => self::VALUE_WELLKNOWN_SHORT,

		// WMDE Technical Wishes team
		'usecodemirror' => self::VALUE_WELLKNOWN_SHORT,
		'popups' => self::VALUE_WELLKNOWN_SHORT,
		'popupsreferencepreviews' => self::VALUE_BETA_FEATURE,

		// Structured Data team
		'echo-subscriptions-web-image-suggestions' => self::VALUE_WELLKNOWN_SHORT,
		'echo-subscriptions-email-image-suggestions' => self::VALUE_WELLKNOWN_SHORT,
		'echo-subscriptions-push-image-suggestions' => self::VALUE_WELLKNOWN_SHORT,

		// Search Preview
		'searchpreview' => self::VALUE_WELLKNOWN_SHORT,
	];

	/**
	 * Log an event when a tracked user preference is changed.
	 *
	 * Note that logging must be explicitly enabled for a property in order for
	 * tracking to take place.
	 *
	 * This hook is triggered when User::saveOptions() is called after User::setOption().
	 * For example when submitting from Special:Preferences or Special:MobileOptions,
	 * or from any client-side interfaces that use api.php?action=options (ApiOptions)
	 * to store user preferences.
	 *
	 * @see https://meta.wikimedia.org/wiki/Schema:PrefUpdate
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SaveUserOptions
	 *
	 * @param UserIdentity $user The user whose options are being saved
	 * @param array &$modifiedOptions The options being saved
	 * @param array $originalOptions The original options being replaced
	 */
	public static function onSaveUserOptions(
		UserIdentity $user,
		array &$modifiedOptions,
		array $originalOptions
	): void {
		if ( !self::isUserInitiated() ) {
			return;
		}

		// An empty $originalOptions array will almost certainly cause spurious PrefUpdates events to be issued,
		// and indicates a likely bug in the preference handling code causing the save. Emit a warning and abort.
		if ( empty( $originalOptions ) ) {
			LoggerFactory::getInstance( 'WikimediaEvents' )->warning(
				'WikimediaEventsHooks::onUserSaveOptions called with empty originalOptions array. ' .
				'Aborting to avoid creating spurious PrefUpdate events.',
				// Record a stack trace to help track down the source of this call.
				// https://www.mediawiki.org/wiki/Manual:Structured_logging#Add_structured_data_to_logging_context
				[ 'exception' => new RuntimeException() ]
			);
			return;
		}

		$now = MWTimestamp::now( TS_MW );
		$betaLoaded = ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' );

		foreach ( $modifiedOptions as $optName => $optValue ) {
			$trackType = self::PROPERTY_TRACKLIST[$optName] ?? null;
			if ( $betaLoaded && $trackType === self::VALUE_BETA_FEATURE ) {
				$optValue = BetaFeatures::isFeatureEnabled( $user, $optName );
				$prevValue = BetaFeatures::isFeatureEnabled( $user, $optName, $originalOptions );
			} else {
				$prevValue = $originalOptions[$optName] ?? null;
			}
			// Use loose comparison because the implicit default form declared in PHP
			// often uses integers and booleans, whereas the stored format often uses
			// strings (e.g. "" vs false)
			if ( $prevValue != $optValue ) {
				$event = self::createPrefUpdateEvent( $user, $optName, $optValue, $now );
				if ( $event !== false ) {
					EventLogging::logEvent( 'PrefUpdate', -1, $event );
				}
			}
		}
	}

	/**
	 * Format a changed user preference as a PrefUpdate event, or false to send none.
	 *
	 * @param UserIdentity $user
	 * @param string $optName
	 * @param string $optValue
	 * @param string $now
	 * @return false|array
	 */
	private static function createPrefUpdateEvent( UserIdentity $user, $optName, $optValue, $now ) {
		$trackType = self::PROPERTY_TRACKLIST[$optName] ?? null;
		if ( $trackType === null ) {
			// Not meant to be tracked.
			return false;
		}

		if ( $trackType === self::VALUE_WELLKNOWN_SHORT ||
			$trackType === self::VALUE_BETA_FEATURE
		) {
			if ( strlen( $optValue ) > self::SHORT_MAX_LEN ) {
				trigger_error( "Unexpected value for $optName in PrefUpdate", E_USER_WARNING );
				return false;
			}
			$trackedValue = $optValue;
		} elseif ( $trackType === self::VALUE_NEWLINE_COUNT ) {
			// NOTE!  PrefUpdate has been migrated to Event Platform,
			// and is no longer using the metawiki based schema.  This -1 revision_id
			// will be overridden by the value of the EventLogging Schemas extension attribute
			// set in extension.json.
			$trackedValue = count( preg_split( '/\n/', $optValue, -1, PREG_SPLIT_NO_EMPTY ) );
		} else {
			trigger_error( "Unknown handler for $optName in PrefUpdate", E_USER_WARNING );
			return false;
		}
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();

		return [
			'version' => self::MAJOR_VERSION,
			'userId' => $user->getId(),
			'saveTimestamp' => $now,
			'property' => $optName,
			// Encode value as JSON.
			// This is parseable and allows a consistent type for validation.
			'value' => FormatJson::encode( $trackedValue ),
			'isDefault' => $userOptionsLookup->getDefaultOption( $optName ) == $optValue,
			'bucketedUserEditCount' => UserBucketProvider::getUserEditCountBucket( $user ),
		];
	}

	/**
	 * Given the global state of the application, gets whether or not *we think* that the user
	 * initiated the preference update rather than, say, MediaWiki or an extension doing so.
	 *
	 * @return bool
	 */
	private static function isUserInitiated(): bool {
		// TODO (mattflaschen, 2013-06-13): Ideally this would be done more cleanly without looking
		// explicitly at page names and URL parameters. Maybe a $userInitiated flag passed to
		// User::saveSettings would work.

		if (
			defined( 'MW_API' )
			&& RequestContext::getMain()->getRequest()->getRawVal( 'action' ) === 'options'
		) {
			return true;
		}

		$title = RequestContext::getMain()->getTitle();

		if ( $title === null ) {
			return false;
		}

		foreach ( [ 'Preferences', 'MobileOptions' ] as $page ) {
			if ( $title->isSpecial( $page ) ) {
				return true;
			}
		}

		return false;
	}
}
