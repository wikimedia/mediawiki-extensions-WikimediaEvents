<?php

namespace WikimediaEvents;

use EventLogging;
use FormatJson;
use OutputPage;
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
	 * Handler for UserSaveOptions hook.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserSaveOptions
	 * @param User $user user whose options are being saved
	 * @param array &$options Options being saved
	 * @return bool true in all cases
	 */
	public static function onUserSaveOptions( $user, &$options ) {
		// Modified version of original method from the Echo extension
		$out = RequestContext::getMain()->getOutput();
		// Capture user options saved via Special:Preferences, Special:MobileOptions or ApiOptions

		// TODO (mattflaschen, 2013-06-13): Ideally this would be done more cleanly without
		// looking explicitly at page names and URL parameters.
		// Maybe a userInitiated flag passed to saveSettings would work.
		if ( self::isKnownSettingsPage( $out )
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
					EventLogging::logEvent( 'PrefUpdate', 5563398, $event );
				}
			}
		}

		return true;
	}

	/**
	 * Helper method to verify that hook is triggered on special page
	 * @param OutputPage $out Output page
	 * @return bool Returns true, if request is sent to one of $allowedPages special page
	 */
	private static function isKnownSettingsPage( OutputPage $out ) {
		$allowedPages = [ 'Preferences', 'MobileOptions' ];
		$title = $out->getTitle();
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
