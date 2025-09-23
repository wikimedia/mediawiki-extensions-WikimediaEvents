/**
 * A simple instrument that tries to detect bots by sending events with delays.
 * If the current user agent is in sample for the "bot-detection" instrument, it will
 * try to send the event.
 *
 */

const INSTRUMENT_NAME = 'simple-bot-detection';

mw.loader.using( 'ext.xLab' ).then( () => {
	const instrument = mw.xLab.getInstrument( INSTRUMENT_NAME );

	// if this fires, we know js is running and ad-block didn't stop it
	instrument.submitInteraction( 'page-load' );
	setTimeout( () => {
		// if this fires, we know it was not an immediate disconnect
		instrument.submitInteraction( 'after-short-delay' );
	}, 100 );
	setTimeout( () => {
		// if this fires, either a bot waits around for a long time or it's a human
		instrument.submitInteraction( 'after-delay' );
	}, 1100 );
} );
