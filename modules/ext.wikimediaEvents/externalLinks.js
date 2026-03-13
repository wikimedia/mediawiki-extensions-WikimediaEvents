/**
 * Instrument clicks on external links to popular domains in the content area of the page.
 *
 * See https://phabricator.wikimedia.org/T419837
 */

const config = require( './config.json' );

function shouldTrackLink( host ) {
	host = '.' + host;
	for ( const prefix of config.WikimediaEventsExternalLinkTrackedDomains ) {
		if ( host.endsWith( '.' + prefix ) ) {
			return true;
		}
	}
	return false;
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
		// Ensure that this host is of interest to us and that it's a valid value for Prometheus
		if (
			!this.host ||
			!shouldTrackLink( this.host ) ||
			!/^[A-Za-z0-9_.+-]+$/.test( this.host ) ) {
			return;
		}

		// Prometheus doesn't support labels with dots in values
		const host = this.host.replace( /\./g, '_' );
		const wiki = mw.config.get( 'wgDBname' );
		// Normally, we'd use mw.track here, but events created this way don't get sent in Chrome before
		// leaving the page (T419956). This is critical, as most link clicks lead to the user leaving the page.
		const serializedData = `mediawiki_WikimediaEvents_extLinkClick_total:1|c%7C%23wiki:${ wiki },domain:` + host;
		mw.eventLog.sendBeacon( config.WMEStatsBeaconUri + '?' + serializedData );
	} );
}

module.exports = setupInstrumentation;
