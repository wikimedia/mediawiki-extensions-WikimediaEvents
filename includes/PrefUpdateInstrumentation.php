<?php

namespace WikimediaEvents;

use EventLogging;
use FormatJson;
use MWTimestamp;
use RequestContext;
use User;

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
	 * @var int The revision ID of the PrefUpdate schema that we're using.
	 */
	private const REV_ID = 19799589;

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
	 * @var string[] List of preferences (aka user properties, aka user options)
	 * to track via EventLogging when they are changed (T249894)
	 */
	private const PROPERTY_TRACKLIST = [
		// Reading Web team
		'skin' => self::VALUE_WELLKNOWN_SHORT,
		'mfMode' => self::VALUE_WELLKNOWN_SHORT,
		'mf_amc_optin' => self::VALUE_WELLKNOWN_SHORT,
		'VectorSkinVersion' => self::VALUE_WELLKNOWN_SHORT,
		'popups' => self::VALUE_WELLKNOWN_SHORT,
		'popupsreferencepreviews' => self::VALUE_WELLKNOWN_SHORT,

		// Editing team
		'discussiontools-betaenable' => self::VALUE_WELLKNOWN_SHORT,
		'betafeatures-auto-enroll'  => self::VALUE_WELLKNOWN_SHORT,

		// AHT
		'echo-notifications-blacklist' => self::VALUE_NEWLINE_COUNT,
		'email-blacklist' => self::VALUE_NEWLINE_COUNT,

		// Growth team
		'growthexperiments-help-panel-tog-help-panel' => self::VALUE_WELLKNOWN_SHORT,
		'growthexperiments-homepage-enable' => self::VALUE_WELLKNOWN_SHORT,
		'growthexperiments-homepage-pt-link' => self::VALUE_WELLKNOWN_SHORT,

		// WMDE Technical Wishes team: T260138
		'usecodemirror' => self::VALUE_WELLKNOWN_SHORT,
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
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserSaveOptions
	 *
	 * @param User $user The user whose options are being saved
	 * @param array &$options The options being saved
	 * @param array $originalOptions The original options being replaced
	 */
	public static function onUserSaveOptions(
		User $user,
		array &$options,
		array $originalOptions
	) : void {
		if ( !self::isUserInitiated() ) {
			return;
		}

		$now = MWTimestamp::now( TS_MW );

		foreach ( $options as $optName => $optValue ) {
			$prevValue = $originalOptions[$optName] ?? null;
			// Use loose comparision because the implicit default form declared in PHP
			// often uses integers and booleans, whereas the stored format often uses
			// strings (e.g. "" vs false)
			if ( $prevValue != $optValue ) {
				$event = self::createPrefUpdateEvent( $user, $optName, $optValue, $now );
				if ( $event !== false ) {
					EventLogging::logEvent( 'PrefUpdate', self::REV_ID, $event );
				}
			}
		}
	}

	/**
	 * Format a changed user preference as a PrefUpdate event, or false to send none.
	 *
	 * @param User $user
	 * @param string $optName
	 * @param string $optValue
	 * @param string $now
	 * @return false|array
	 */
	private static function createPrefUpdateEvent( User $user, $optName, $optValue, $now ) {
		$trackType = self::PROPERTY_TRACKLIST[$optName] ?? null;
		if ( $trackType === null ) {
			// Not meant to be tracked.
			return false;
		}

		if ( $trackType === self::VALUE_WELLKNOWN_SHORT ) {
			if ( strlen( $optValue ) > self::SHORT_MAX_LEN ) {
					trigger_error( "Unexpected value for $optName in PrefUpdate", E_USER_ERROR );
					return false;
			}
			$trackedValue = $optValue;
		} elseif ( $trackType === self::VALUE_NEWLINE_COUNT ) {
			$trackedValue = count( preg_split( '/\n/', $optValue, -1, PREG_SPLIT_NO_EMPTY ) );
		} else {
			trigger_error( "Unknown handler for $optName in PrefUpdate", E_USER_ERROR );
			return false;
		}

		return [
			'version' => self::MAJOR_VERSION,
			'userId' => $user->getId(),
			'saveTimestamp' => $now,
			'property' => $optName,
			// Encode value as JSON.
			// This is parseable and allows a consistent type for validation.
			'value' => FormatJson::encode( $trackedValue ),
			'isDefault' => User::getDefaultOption( $optName ) == $optValue,
			'bucketedUserEditCount' => self::getBucketedUserEditCount( $user ),
		];
	}

	/**
	 * Buckets a user's edit count, e.g. "5-99 edits".
	 *
	 * See https://phabricator.wikimedia.org/T169672 for discussion about why you would want to do
	 * this.
	 *
	 * @param User $user
	 * @return string
	 */
	private static function getBucketedUserEditCount( User $user ) : string {
		$editCount = $user->getEditCount();

		if ( $editCount >= 1000 ) {
			return '1000+ edits';
		}

		if ( $editCount >= 100 ) {
			return '100-999 edits';
		}

		if ( $editCount >= 5 ) {
			return '5-99 edits';
		}

		if ( $editCount >= 1 ) {
			return '1-4 edits';
		}

		return '0 edits';
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
