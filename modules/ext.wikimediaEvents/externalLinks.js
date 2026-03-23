/**
 * Instrument clicks on external links to popular domains in the content area of the page.
 *
 * See https://phabricator.wikimedia.org/T419837
 */

const config = require( './config.json' );

function getTrackingPrefix( host ) {
	host = '.' + host;
	for ( const prefix of config.WikimediaEventsExternalLinkTrackedDomains ) {
		if ( host.endsWith( '.' + prefix ) ) {
			return prefix;
		}
	}
	return null;
}

function setupInstrumentation() {
	// Only instrument logged-out sessions
	if (
		mw.config.get( 'wgUserId' ) ||
		!config.WikimediaEventsExternalLinkInstrumentation ||
		!config.WMEStatsBeaconUri
	) {
		return;
	}

	// eslint-disable-next-line no-jquery/no-global-selector
	const $links = $( '.mw-parser-output a.external' );
	$links.on( 'mousedown', function () {
		if ( !this.host ) {
			return;
		}

		// Ensure that this host is of interest to us and normalize it to just the domain given in
		// config (to aggregate over subdomains)
		const trackingPrefix = getTrackingPrefix( this.host );
		if ( trackingPrefix === null ) {
			return;
		}

		// Prometheus doesn't support labels with dots in values
		const domain = trackingPrefix.replace( /\./g, '_' );
		const wiki = mw.config.get( 'wgDBname' );
		// Normally, we'd use mw.track here, but events created this way don't get sent
		// in Chrome before leaving the page (T419956). This is critical, as most
		// link clicks lead to the user leaving the page.
		const serializedData = `mediawiki_WikimediaEvents_extLinkClick_total:1|c%7C%23wiki:${ wiki },domain:` + domain;
		mw.eventLog.sendBeacon( config.WMEStatsBeaconUri + '?' + serializedData );
	} );
}

module.exports = setupInstrumentation;
