<?php

namespace WikimediaEvents;

use EventLogging;
use FormatJson;
use RequestContext;
use Title;
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
	 * @const int REV_ID The revision ID of the PrefUpdate schema that we're using.
	 */
	const REV_ID = 5563398;

	/**
	 * Logs a <a href="https://meta.wikimedia.org/wiki/Schema:PrefUpdate">PrefUpdate</a> event for
	 * every preference that the user has changed.
	 *
	 * Note well that logging only occurs when the user changes their preferences via the
	 * Preferences or MobileOptions special pages, the latter of which is provided by the
	 * MobileFrontend extension, or via the
	 * <a href="https://www.mediawiki.org/wiki/API:Options">options API</a>.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserSaveOptions
	 *
	 * @param User $user The user whose options are being saved
	 * @param array &$options The options being saved
	 */
	public static function onUserSaveOptions( User $user, array &$options ) : void {
		// Modified version of original method from the Echo extension
		$out = RequestContext::getMain()->getOutput();
		// Capture user options saved via Special:Preferences, Special:MobileOptions or ApiOptions

		// TODO (mattflaschen, 2013-06-13): Ideally this would be done more cleanly without
		// looking explicitly at page names and URL parameters.
		// Maybe a userInitiated flag passed to saveSettings would work.
		if ( self::isKnownSettingsPage( $out->getTitle() )
			|| ( defined( 'MW_API' ) && $out->getRequest()->getVal( 'action' ) === 'options' )
		) {
			// $clone is the current user object before the new option values are set
			$clone = User::newFromId( $user->getId() );

			$commonData = [
				'version' => '1',
				'userId' => $user->getId(),
				'saveTimestamp' => wfTimestampNow(),
			];

			foreach ( $options as $optName => $optValue ) {
				// loose comparision is required since some of the values
				// are not consistent in the two variables, eg, '' vs false
				if ( $clone->getOption( $optName ) != $optValue ) {
					$event = [
						'property' => $optName,
						// Encode value as JSON.
						// This is parseable and allows a consistent type for validation.
						'value' => FormatJson::encode( $optValue ),
						'isDefault' => User::getDefaultOption( $optName ) == $optValue,
					] + $commonData;
					EventLogging::logEvent( 'PrefUpdate', self::REV_ID, $event );
				}
			}
		}
	}

	/**
	 * Gets whether or not the page is a settings page, i.e. either the Preferences or
	 * MobileOptions special page, the latter of which is provided by the MobileFrontend extension.
	 *
	 * @param Title|null $title The title that represents the page
	 * @return bool
	 */
	private static function isKnownSettingsPage( Title $title = null ) : bool {
		$allowedPages = [ 'Preferences', 'MobileOptions' ];
		if ( $title === null ) {
			return false;
		}
		foreach ( $allowedPages as $page ) {
			if ( $title->isSpecial( $page ) ) {
				return true;
			}
		}
		return false;
	}
}
