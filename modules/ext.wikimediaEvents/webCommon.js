'use strict';

/**
 * Check if user's group contains bot.
 *
 * @return {boolean}
 */
function isUserBot() {
	const userGroups = mw.config.get( 'wgUserGroups' ) || [];
	return userGroups.includes( 'bot' );
}

/**
 * Get the wiki name from mw.config.
 *
 * @return {string} Wiki name
 */
function getWikiName() {
	return mw.config.get( 'wgDBname', '' );
}

/**
 * Get the wiki skin from mw.config.
 *
 * @return {string} Wiki skin
 */

function getSkin() {
	return mw.config.get( 'skin', '' );
}

// Export the isUserBot and getWikiName functions for usage in other modules
module.exports = () => ( {
	wiki: getWikiName(),
	skin: getSkin(),
	is_bot: isUserBot()
} );
