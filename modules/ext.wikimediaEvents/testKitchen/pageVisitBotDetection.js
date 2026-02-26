/**
 * This instrument sends one event at page load, and is meant to run on 100%
 *   of enwiki traffic.  It sends two custom pieces of information and some
 *   contextual attributes:
 *
 *   in action_context:
 *     bot=1 if navigator.webdriver is present
 *     bot=2 if navigator shows more than 70 CPU cores
 *     bot=0 by default
 *
 *   in action_source:
 *     the cyrb53 hash of path + search
 *       NOTE: investigating how the caches send data to the Data Lake informs
 *       us that the browser's location.pathname + location.search will not
 *       match uri_path + uri_query in some edge cases, like URL-decoding,
 *       capitalization, etc.  Varnish, ATS, and MediaWiki all do their own
 *       normalization of the path.  The Data Lake only sees what HA Proxy
 *       sends us, before those three layers of normalization.  And the
 *       pageview definition tries to match this, but probably is not perfect.
 *       More likely to match is the page id (if sent).  See more on wikitech:
 *         Data_Platform/Data_Lake/Traffic/Pageviews/Redirects
 *
 *   contextual attributes:
 *     ip address - used to join to webrequest
 *     page id - preferred join key to webrequest, fall back to title hash
 *     page namespace id - (same as above)
 */
/* eslint-disable no-bitwise */

const INSTRUMENT_NAME = 'bot-detection-2026-03';
const SCHEMA_ID = '/analytics/product_metrics/web/base_with_ip/2.0.0';

mw.loader.using( 'ext.testKitchen' ).then( () => {
	const instrument = mw.testKitchen.getInstrument( INSTRUMENT_NAME );
	instrument.setSchema( SCHEMA_ID );

	let botScore = 0;
	if ( navigator.webdriver === true ) {
		botScore |= 1;
	}
	if ( navigator.hardwareConcurrency > 70 ) {
		botScore |= 2;
	}

	const interactionData = {
		action_context: 'bot=' + botScore,
		action_source: cyrb53( location.pathname + location.search, 0 )
	};
	instrument.send( 'page_load', interactionData );
} );

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
