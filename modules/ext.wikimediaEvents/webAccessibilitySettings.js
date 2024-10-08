'use strict';

/**
 * This module provides utility functions to retrieve user preferences for
 * font size, interface width, popups, mediaviewer, and dark mode
 * from the mw.user.clientPrefs and mw.user.options object.
 */

/**
 * Get the font preferences from mw.user.clientPrefs.
 *
 * @return {string} Wiki font preference, or "N/A" if not available.
 */
function getFont() {
	const size = mw.user.clientPrefs.get( 'vector-feature-custom-font-size' ) || mw.user.clientPrefs.get( 'mf-font-size' ) || '0';

	// T349862: On mobile convert the new pref values to their desktop equivalents for logging consistency
	switch ( size ) {
		case 'large':
			return '3';
		case 'regular':
			return '2';
		case 'small':
			return '1';
		default:
			return size;
	}
}

/**
 * Get the page width preferences from mw.user.clientPrefs.
 *
 * @return {boolean} Wiki width preference.
 */
function getInterfaceWidth() {
	return mw.user.clientPrefs.get( 'vector-feature-limited-width' ) === '0';
}

/**
 * Get the page preview preferences from mw.popups API.
 *
 * @return {boolean} Wiki page preview
 */
function getPagePreviewSettings() {
	return mw.popups ? mw.popups.isEnabled() : false;
}

/**
 * Check if media viewer is enabled.
 *
 * @return {boolean} Media viewer preference.
 *
 * @note This function checks the current status of the Media Viewer extension
 * using existing configurations. Please note that this method may become
 * deprecated in the future in favor of a more stable and efficient API.
 * Track the progress and updates related to this functionality at
 * Phabricator ticket T348026.
 */
function getMediaViewerSettings() {
	const isUserAnon = mw.user.isAnon();
	const isMediaViewerEnabled =
		mw.config.get( 'wgMediaViewer' ) === true;
	const isMediaViewerEnabledByDefault =
		mw.config.get( 'wgMediaViewerEnabledByDefault' ) === true;
	const isMediaViewerOnClickEnabled =
		mw.config.get( 'wgMediaViewerOnClick' ) === true;

	const anonDisabledMV =
		isUserAnon && mw.storage.get( 'wgMediaViewerOnClick' ) !== '0';

	return (
		isMediaViewerEnabled &&
		isMediaViewerEnabledByDefault &&
		isMediaViewerOnClickEnabled &&
		( !isUserAnon || anonDisabledMV )
	);
}

/**
 * Check if there are pinned elements.
 * Generates a function to check if the current skin supports pinned elements.
 * Returns the 'analyticsPinnedState' result for the 'vector-2022' skin, otherwise false.
 *
 * @return {boolean} Pinned elements.
 */
function getPinnedSettings() {
	if ( mw.config.get( 'skin' ) === 'vector-2022' ) {
		// Consumer of skins.vector.js module:
		const skinsVector = require( 'skins.vector.js' );
		const hasPinnedElementsFn = skinsVector.pinnableElement.analyticsPinnedState;
		return hasPinnedElementsFn();
	} else {
		return false;
	}
}

/**
 * @return {string} Get dark mode clientpref value
 */
function getDarkModeSettings() {
	const theme = mw.user.clientPrefs.get( 'skin-theme' );

	// if no set value for the new client pref, fall back to the old behavior
	if ( theme === false ) {
		return mw.user.clientPrefs.get( 'skin-night-mode' ) || '0';
	}

	// otherwise, convert the new pref values to their old equivalents for logging consistency
	switch ( theme ) {
		case 'night':
			return '1';
		case 'os':
			return '2';
		case 'day':
			return '0';
		default:
			return '0'; // ideally this should never happen, but if we get an unusable value we group with day mode
	}
}

/**
 * @return {boolean} Get browser dark mode media status
 */
function getDarkModeBrowserMedia() {
	return window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches;
}

module.exports = () => ( {
	font: getFont(),
	is_full_width: getInterfaceWidth(),
	is_page_preview_on: getPagePreviewSettings(),
	is_pinned: getPinnedSettings(),
	is_media_viewer_enabled: getMediaViewerSettings(),
	is_dark_mode_prepared_by_os: getDarkModeBrowserMedia(),
	dark_mode_setting: getDarkModeSettings(),
	is_dark_mode_on: getDarkModeSettings() === '1' || ( getDarkModeBrowserMedia() && getDarkModeSettings() === '2' )
} );
