'use strict';

/**
 * NOTE: this is for experimental purposes only, for
 * the Web Team's Search Recommendations A/B test
 *
 * SessionLengthInstrumentMixin: Tracks session length.
 * Starts and stops ticks based on user activity, with a
 * reset after inactivity. Integrates with mw.eventLog for
 * analytics. Use `start(streamName, schemaID)` to begin
 * tracking and `stop(streamName)` to end tracking. Based
 * on sessionTick.js for modular reuse across core.
 */

// Constants for session timing, idle, reset, and tick limits.
const NOOP = function () { };

const TICK_MS = 30000;
const IDLE_MS = 100000;
const RESET_MS = 1800000;
const DEBOUNCE_MS = 5000;
const TICK_LIMIT = Math.ceil( RESET_MS / TICK_MS );

const KEY_LAST_TIME = 'mp-sessionTickLastTickTime';
const KEY_COUNT = 'mp-sessionTickTickCount';

// Stores active sessions with their streamName and schemaID.
// xLab users may pass in an Instrument/Experiment object.
const state = new Map();

// Checks if browser supports passive event listeners.
// Returns true if supported, false otherwise.
function supportsPassiveEventListeners() {
	let supportsPassive = false;
	try {
		const options = Object.defineProperty( {}, 'passive', {
			get: function () {
				supportsPassive = true;
				return false;
			}
		} );
		window.addEventListener( 'testPassiveOption', NOOP, options );
		window.removeEventListener( 'testPassiveOption', NOOP, options );
	} catch ( e ) {
		// Silently fail.
	}
	return supportsPassive;
}

// Optimization:
//
// If the browser doesn't support the Page Visibility API or passive event
// listeners, then stop processing and export the null implementation of
// the API so that dependent scripts don't break.
if ( document.hidden === undefined && !supportsPassiveEventListeners() ) {
	mw.SessionLengthInstrumentMixin = {
		start: NOOP,
		stop: NOOP
	};
	return;
}

function sessionReset() {
	mw.storage.session.set( KEY_COUNT, 0 );
}

function sessionTick( incr ) {
	if ( incr > TICK_LIMIT ) {
		throw new Error( 'Session ticks exceed limit' );
	}

	const count = ( Number( mw.storage.session.get( KEY_COUNT ) ) || 0 );

	state.forEach( ( { schemaID, data, instrument }, streamName ) => {
		// If an experiment/instrument is passed,
		// an `instrument_name` property should be included in the passed data.
		if ( instrument ) {
			data = Object.assign( {
				action_context: count.toString()
			}, data );
			// xLab Experiment objects are API-compatible with
			// Instrument objects' `submitInteraction` method; either can
			// be used here.
			instrument.submitInteraction( 'tick', data );
		} else {
			data = Object.assign( {
				action_context: count.toString(),
				instrument_name: 'SessionLengthMixin'
			}, data );
			mw.eventLog.submitInteraction(
				streamName,
				schemaID,
				'tick',
				data
			);
		}
	} );

	mw.storage.session.set( KEY_COUNT, count + incr );
}

// Main regulator function to manage session ticking.
function regulator() {
	let tickTimeout = null;
	let idleTimeout = null;
	let debounceTimeout = null;

	// Runs tick logic based on time gap since last activity.
	function run() {
		const now = Date.now();
		const gap = now - ( Number( mw.storage.session.get( KEY_LAST_TIME ) ) || 0 );
		const count = Number( mw.storage.session.get( KEY_COUNT ) ) || 0;
		if ( count === 0 || gap > RESET_MS ) {
			// Reset session if idle time exceeds limit.
			mw.storage.session.set( KEY_LAST_TIME, now );
			sessionReset();
			sessionTick( 1 ); // Start tick
		} else if ( gap > TICK_MS ) {
			mw.storage.session.set( KEY_LAST_TIME, now );
			sessionTick( 1 );
		}
		// Schedule next tick.
		tickTimeout = setTimeout( run, TICK_MS );
	}

	// Stops all timeouts to mark session as inactive.
	function setInactive() {
		clearTimeout( idleTimeout );
		clearTimeout( tickTimeout );
		clearTimeout( debounceTimeout );
		tickTimeout = null;
		debounceTimeout = null;
	}

	// Activates session and restarts tick if stopped.
	function setActive() {
		if ( tickTimeout === null ) {
			run();
		}
		clearTimeout( idleTimeout );
		idleTimeout = setTimeout( setInactive, IDLE_MS );
	}

	// Debounces frequent events to limit setActive calls.
	function setActiveDebounce() {
		if ( !debounceTimeout ) {
			debounceTimeout = setTimeout( () => {
				clearTimeout( debounceTimeout );
				debounceTimeout = null;
			}, DEBOUNCE_MS );

			mw.requestIdleCallback( setActive );
		}
	}

	// Toggles activity based on page visibility.
	function onVisibilitychange() {
		if ( document.hidden ) {
			setInactive();
		} else {
			setActive();
		}
	}

	// Set event listeners for user activity and visibility.
	document.addEventListener( 'visibilitychange', onVisibilitychange, false );
	window.addEventListener( 'click', setActiveDebounce, false );
	window.addEventListener( 'keyup', setActiveDebounce, false );
	window.addEventListener( 'scroll', setActiveDebounce, { passive: true } );

	onVisibilitychange();
}

// API
// ===

/**
 * @memberof mw.wikimediaEvents
 */
const SessionLengthInstrumentMixin = {
	state,
	/**
	 * start( streamName, schemaID, ?data ) - global submitInteraction()
	 * start( instrument, ?data ) - calls instrument.submitInteraction()
	 * start( experiment, ?data ) - calls experiment.submitInteraction()
	 *
	 * @param {string|Instrument|mw.xLab.Experiment} [dest] string, Instrument or Experiment
	 * @param {string|Object} [schemaOrData] string or data object
	 * @param {Object?} [data]
	 */
	start( dest, schemaOrData = {}, data = {} ) {
		if ( typeof dest === 'string' ) {
			const streamName = dest;
			const schemaID = schemaOrData;
			state.set( streamName, { schemaID, data } );
		} else if ( typeof dest === 'object' ) {
			data = schemaOrData;
			if ( dest.submitInteraction ) {
				const instrument = dest;
				state.set( instrument, { instrument, data } );
			} else {
				throw new Error(
					'invalid Instrument or Experiment: it should have a submitInteraction() method'
				);
			}
		} else {
			throw new Error( 'invalid streamName, Instrument, or Experiment' );
		}

		// Start algorithm
		regulator();
	},
	/**
	 * @param {string|Instrument|mw.xLab.Experiment} [dest] string, Instrument, or Experiment
	 */
	stop( dest ) {
		state.delete( dest );
	}
};

// Cleanup old local storage data
mw.storage.remove( KEY_COUNT );
mw.storage.remove( KEY_LAST_TIME );

module.exports = { SessionLengthInstrumentMixin };
