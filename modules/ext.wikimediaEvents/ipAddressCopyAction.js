/*!
 * The instrument for the Anti-Harassment Tools team's "IP address copy action" metric.
 *
 * This instrument increments a counter in StatsD if the user copies an IP address when viewing
 * the history of a page or a special page in the allowlist codified in `SPECIAL_PAGE_ALLOWLIST`
 * below. No information about the user nor the IP address that they copied is included in the
 * name of the counter.
 *
 * See https://phabricator.wikimedia.org/T273021 and associated tasks for further detail.
 */

var SPECIAL_PAGE_ALLOWLIST = [
	'Recentchanges',
	'Log',
	'Investigate',
	'Contributions'
];

function isEnabled() {
	var enabled = !!require( './config.json' ).ipAddressCopyActionEnabled;
	var specialPageName = mw.config.get( 'wgCanonicalSpecialPageName' );

	return enabled && (
		mw.config.get( 'wgAction' ) === 'history' ||
		SPECIAL_PAGE_ALLOWLIST.indexOf( specialPageName ) !== -1
	);
}

/**
 * Increments the appropriate metric in StatsD via [statsv](https://wikitech.wikimedia.org/wiki/Graphite#statsv).
 *
 * The metric will be one of the following:
 *
 * - `MediaWiki.ipinfo_address_copy.action_history`
 * - `MediaWiki.ipinfo_address_copy.special_<pagename>`,
 *    where `special_<pagename>` uses the lowercase canonicalized name of the special page,
 *    such as `special_log`.
 */
function log() {
	var bucketNamePrefix = 'MediaWiki.ipinfo_address_copy';

	var bucketNameSuffix;
	if ( mw.config.get( 'wgAction' ) === 'history' ) {
		bucketNameSuffix = 'action_history';
	} else {
		var specialPageName = mw.config.get( 'wgCanonicalSpecialPageName' );
		bucketNameSuffix = 'special_' + specialPageName.toLowerCase();
	}

	mw.track( 'counter.' + bucketNamePrefix + '.' + bucketNameSuffix );
	mw.track( 'counter.' + bucketNamePrefix + '_by_wiki.' + mw.config.get( 'wgDBname' ) + '.' + bucketNameSuffix, 1 );
}

function main() {
	// Some IP addresses are text nodes in #firstHeading (see Special:Contributions) and others
	// are a.mw-anonuserlink elements (see Special:RecentChanges, for example). In order to
	// capture as many edge cases as possible, filter all copy events to see whether the user
	// copied just an IP address.
	document.addEventListener( 'copy', function () {
		var selection = document.getSelection().toString();

		// An IPv4-mapped IPv6 address is 45 characters. An IPv6 address with a zone index _could_
		// be longer but it is unlikely. mw.util.isIPv6Address() does not validate either but
		// testing the length of the selection is far cheaper than calling mw.util.isIPAddress().
		if ( selection.length < 100 && mw.util.isIPAddress( selection.trim() ) ) {
			log();
		}
	} );
}

module.exports = {
	isEnabled: isEnabled,
	log: log
};

if ( !window.QUnit && isEnabled() ) {
	mw.requestIdleCallback( main );
}
