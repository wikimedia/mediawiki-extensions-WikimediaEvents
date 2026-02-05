/**
 * This instrument is sending data four different ways to understand
 *  a discrepancy in sending and receiving we observed when running
 *  bot-detection-2026-01.
 */
/* eslint-disable no-bitwise */

// right now this is just a comparison of sending methods (see T416472)
const INSTRUMENT_NAME = 'bot-detection-2026-02';
const SCHEMA_ID = '/analytics/product_metrics/web/base_with_ip/2.0.0';

mw.loader.using( 'ext.testKitchen' ).then( () => {
	const instrument = mw.testKitchen.getInstrument( INSTRUMENT_NAME );
	if ( !instrument.isStreamInSample() ) {
		return;
	}
	instrument.setSchemaID( SCHEMA_ID );

	let botScore = 0;
	if ( navigator.webdriver === true ) {
		botScore |= 1;
	}
	if ( navigator.hardwareConcurrency > 70 ) {
		botScore |= 2;
	}

	// fill in timing in centiseconds when sending the event below
	let interactionData = {
		action_context: 'bot=' + botScore,
		action_source: cyrb53( location.pathname + location.search, 0 )
	};
	instrument.submitInteraction( 'page_load', interactionData );
	sendOld( 'old_page_load', interactionData );

	let alreadySent = false;
	// used to calculate session time when sending the event
	const now = Date.now();
	window.addEventListener( 'pagehide', () => {
		if ( alreadySent ) {
			return;
		}
		// if this fires, we know js is running and ad-block didn't stop it
		alreadySent = true;
		interactionData = {
			action_context: 't=' + Math.floor( ( Date.now() - now ) / 100 )
		};
		instrument.submitInteraction( 'page_hide', interactionData );
		sendOld( 'old_page_hide', interactionData );
	} );
	window.addEventListener( 'visibilitychange', () => {
		if ( alreadySent ) {
			return;
		}
		if ( document.hidden ) {
			// if this fires, we know js is running and ad-block didn't stop it
			alreadySent = true;
			interactionData = {
				action_context: 't=' + Math.floor( ( Date.now() - now ) / 100 )
			};
			instrument.submitInteraction( 'page_hide', interactionData );
			sendOld( 'old_page_hide', interactionData );
		}
	} );
} );

/**
 * use sendBeacon to send a manually-crafted event to the
 * old TestKitchen instrument endpoint (intake-analytics...)
 *
 * @param {string} action main name of the event
 * @param {Object} interactionData object with action_context and maybe action_source
 */
function sendOld( action, interactionData ) {
	if ( navigator.sendBeacon ) {
		const OLD_ENDPOINT = 'https://intake-analytics.wikimedia.org/v1/events';
		const STREAM_NAME = 'product_metrics.web_base_with_ip';

		// Collect data using manual logging, event sent to endpoint over HTTPS:
		const eventData = {
			$schema: SCHEMA_ID,
			meta: {
				stream: STREAM_NAME,
				domain: window.location.hostname
			},
			agent: {
				client_platform: 'mediawiki_js',
				// ext.eventLogging.metricsPlatform/MediaWikiMetricsClientIntegration.js#69
				client_platform_family: mw.config.get( 'wgMFMode' ) !== null ? 'mobile_browser' : 'desktop_browser'
			},
			action: action,
			action_context: interactionData.action_context,
			action_source: interactionData.action_source,
			instrument_name: INSTRUMENT_NAME
		};
		navigator.sendBeacon( OLD_ENDPOINT, JSON.stringify( eventData ) );
	}
}

/**
 * Hash function with no security but low collisions
 * See https://github.com/bryc/code/blob/master/jshash/experimental/cyrb53.js
 *
 * @param {string} str what to hash
 * @param {string} seed usually 0 and honestly not sure what seeds do here :)
 *
 * @return {string} a 53-bit string hash of the input str
 */
function cyrb53( str, seed ) {
	let h1 = 0xdeadbeef ^ seed;
	let h2 = 0x41c6ce57 ^ seed;
	for ( let i = 0, ch; i < str.length; i++ ) {
		ch = str.charCodeAt( i );
		h1 = Math.imul( h1 ^ ch, 2654435761 );
		h2 = Math.imul( h2 ^ ch, 1597334677 );
	}
	h1 = Math.imul( h1 ^ ( h1 >>> 16 ), 2246822507 );
	h1 ^= Math.imul( h2 ^ ( h2 >>> 13 ), 3266489909 );
	h2 = Math.imul( h2 ^ ( h2 >>> 16 ), 2246822507 );
	h2 ^= Math.imul( h1 ^ ( h1 >>> 13 ), 3266489909 );
	return String( 4294967296 * ( 2097151 & h2 ) + ( h1 >>> 0 ) );
}
