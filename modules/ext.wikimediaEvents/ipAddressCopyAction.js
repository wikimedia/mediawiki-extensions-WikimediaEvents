/**
 * The instrument for the Anti-Harassment Tools team's "IP address copy action" metric.
 *
 * This instrument increments a counter in StatsD if the user copies an IP address when viewing
 * the history of a page or a special page in the allowlist codified in `SPECIAL_PAGE_ALLOWLIST`
 * below. No information about the user nor the IP address that they copied is included in the
 * name of the counter. See https://phabricator.wikimedia.org/T273021 and associated tasks for
 * further detail.
 */

var SPECIAL_PAGE_ALLOWLIST = [
	'Recentchanges',
	'Log',
	'Investigate',
	'Contributions'
];

function isEnabled() {
	var isEnabled = !!require( './config.json' ).ipAddressCopyActionEnabled,
		state = mw.config.get( [ 'wgAction', 'wgCanonicalSpecialPageName' ] );

	return isEnabled && (
		state.wgAction === 'history' ||
		SPECIAL_PAGE_ALLOWLIST.indexOf( state.wgCanonicalSpecialPageName ) !== -1
	);
}

/**
 * Increments the appropriate metric in StatsD via [statsv](https://wikitech.wikimedia.org/wiki/Graphite#statsv).
 *
 * The name of the bucket is derived from the application config and can be:
 *
 * * `IPInfo.copy.action-history`; or
 * * `IPInfo.copy.special-` followed by the lowercase canonicalized name of the special page.
 */
function log() {
	var bucketName = 'IPInfo.ip-address-copy.',
		state = mw.config.get( [ 'wgAction', 'wgCanonicalSpecialPageName' ] );

	if ( state.wgAction === 'history' ) {
		bucketName += 'action-history';
	} else {
		bucketName += 'special-' + state.wgCanonicalSpecialPageName.toLowerCase();
	}

	mw.track( 'counter.' + bucketName );
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
		if ( selection.length > 100 || !mw.util.isIPAddress( selection.trim() ) ) {
			return;
		}

		log();
	} );
}

if ( window.QUnit ) {
	module.exports = {
		isEnabled: isEnabled,
		log: log
	};
} else {
	if ( isEnabled() ) {
		mw.requestIdleCallback( main );
	}
}
