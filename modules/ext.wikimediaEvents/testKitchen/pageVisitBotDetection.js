/**
 * A simple instrument to investigate bot behavior
 * If the current user agent is in sample, it will try to send events.
 * The interactionData for the event will include:
 *
 * action_context will have a key=value; semicolon-separated string with keys:
 *   bot=(a bit flag that looks at a couple of common bot markers)
 *   t=(time in centiseconds between page load and event sent)
 *      NOTE: we use centiseconds to minimize privacy loss with more accurate ms
 *
 * NOTE: this aligned with the server-side definition 99.6% of the time when run on
 *   data from 2025-09-26 T15.
 */

const INSTRUMENT_NAME = 'bot-detection-2026-01';
const SCHEMA_ID = '/analytics/product_metrics/web/base_with_ip/2.0.0';

mw.loader.using( 'ext.testKitchen' ).then( () => {
	const instrument = mw.testKitchen.getInstrument( INSTRUMENT_NAME );
	instrument.setSchemaID( SCHEMA_ID );

	let botScore = 0;
	if ( navigator && navigator.webdriver === true ) {
		botScore |= 1; // eslint-disable-line no-bitwise
	}
	if ( navigator && navigator.hardwareConcurrency > 70 ) {
		botScore |= 2; // eslint-disable-line no-bitwise
	}

	// fill in timing in centiseconds when sending the event below
	const interactionData = {
		action_context: 'bot=' + botScore + ';t='
	};

	let alreadySent = false;
	// used to calculate session time when sending the event
	const now = Date.now();
	window.addEventListener( 'pagehide', () => {
		if ( alreadySent ) {
			return;
		}
		// if this fires, we know js is running and ad-block didn't stop it
		alreadySent = true;
		interactionData.action_context += Math.floor( ( Date.now() - now ) / 100 );
		instrument.submitInteraction( 'page_hide', interactionData );
	} );
	window.addEventListener( 'visibilitychange', () => {
		if ( alreadySent ) {
			return;
		}
		if ( document.hidden ) {
			// if this fires, we know js is running and ad-block didn't stop it
			alreadySent = true;
			interactionData.action_context += Math.floor( ( Date.now() - now ) / 100 );
			instrument.submitInteraction( 'page_hide', interactionData );
		}
	} );
} );
