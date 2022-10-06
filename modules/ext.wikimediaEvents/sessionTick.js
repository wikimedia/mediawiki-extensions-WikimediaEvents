/**
 * Determine the length of a user session.
 *
 * Given an origin, a session is the longest interval during which, for any
 * n minute subinterval, at least one page from the origin is active.
 *
 * Here, this value n is RESET_MS.
 *
 * A page is "inactive" if it is either
 *  1. hidden
 *      As determined by Page Visibility (https://w3.org/TR/page-visibility),
 *      a page in a browser window becomes hidden if:
 *          - the user switches to another tab in the window
 *          - the window is minimized
 *      In particular, the following do _not_ cause a page to be hidden:
 *          - window losing focus
 *          - window being covered with other windows
 *          - activating a screensaver
 *          - turning off the monitor
 *  2. idle
 *      As determined by whether any of the following occurred in the past
 *      msBeforePageBecomesIdle milliseconds:
 *          - click
 *          - keyup
 *          - scroll
 *          - visibilitychange (visible <-> hidden states only)
 *
 * When a page becomes inactive, it does not tick and therefore no longer
 * contributes anything to the session length.
 *
 * If all pages become inactive and remain inactive for a period of at least
 * msBeforeSessionResetDueToInactivity milliseconds, then a page subsequently
 * becoming active will cause a new session to begin. Pages that were inactive
 * remain inactive.
 */
var moduleConfig = require( './config.json' ),
	enabled = moduleConfig.sessionTick,

	// Milliseconds between ticks
	TICK_MS = 60000,
	// Milliseconds before the page tries to idle and check for activity.
	IDLE_MS = 100000,
	// Milliseconds after the page has become idle when the session will be reset.
	RESET_MS = 1800000,
	// Milliseconds before an activity will be recorded again.
	DEBOUNCE_MS = 5000,
	// Should represent the most ticks that could be sent at once
	tickLimit = Math.ceil( RESET_MS / TICK_MS ),
	// Whether the browser supports the 'passive' event listener option.
	supportsPassive = 0,
	// Whether the browser supports localStorage.
	supportsLocalStorage = false;

/**
 * Detect support for EventListenerOptions and set 'supportsPassive' flag.
 * See: https://dom.spec.whatwg.org/#dictdef-addeventlisteneroptions
 */
function detectPassiveEventListenerSupport() {
	var noop = function () {};
	try {
		var options = Object.defineProperty( {}, 'passive', {
			get: function () {
				supportsPassive = 1;
				return false;
			}
		} );
		window.addEventListener( 'testPassiveOption', noop, options );
		window.removeEventListener( 'testPassiveOption', noop, options );
	} catch ( e ) {
		// Silently fail.
	}
}

/**
 * Detect support for localStorage.
 *
 * See https://phabricator.wikimedia.org/T295619 for additional detail.
 */
function detectLocalStorageSupport() {
	try {
		localStorage.setItem( 'localStorageSupport', '1' );
		localStorage.removeItem( 'localStorageSupport' );

		supportsLocalStorage = true;
	} catch ( e ) {
		// Silently fail.
	}
}

/**
 * Publish 'sessionReset' and 'sessionTick' events to mw.track()
 */
function regulator() {
	var
		lastTickTime = 'wmE-sessionTickLastTickTime',
		tickTimeout = null,
		idleTimeout = null,
		debounceTimeout = null;

	function run() {
		var
			now = Date.now(),
			gap = now - ( Number( mw.cookie.get( lastTickTime ) ) || 0 );

		if ( gap > RESET_MS ) {
			mw.cookie.set( lastTickTime, now );
			mw.track( 'sessionReset', 1 );
			mw.track( 'sessionTick', 1 ); // Tick once to start
		} else if ( gap > TICK_MS ) {
			mw.cookie.set( lastTickTime, now - ( gap % TICK_MS ) );
			mw.track( 'sessionTick', Math.floor( gap / TICK_MS ) );
		}

		tickTimeout = setTimeout( run, TICK_MS );
	}

	function setInactive() {
		clearTimeout( idleTimeout );
		clearTimeout( tickTimeout );
		clearTimeout( debounceTimeout );
		tickTimeout = null;
		debounceTimeout = null;
	}

	function setActive() {
		if ( tickTimeout === null ) {
			run();
		}
		clearTimeout( idleTimeout );
		idleTimeout = setTimeout( setInactive, IDLE_MS );
	}

	function setActiveDebounce() {
		if ( !debounceTimeout ) {
			debounceTimeout = setTimeout( function () {
				clearTimeout( debounceTimeout );
				debounceTimeout = null;
			}, DEBOUNCE_MS );
			//
			// Call setActive only after the next frame paints, because
			// it may perform (relatively) expensive cookie I/O.
			//
			mw.requestIdleCallback( setActive );
		}
	}

	function onVisibilitychange() {
		if ( document.hidden ) {
			setInactive();
		} else {
			setActive();
		}
	}

	// Bind handlers to detect browser events
	document.addEventListener( 'visibilitychange', onVisibilitychange, false );
	window.addEventListener( 'click', setActiveDebounce, false );
	window.addEventListener( 'keyup', setActiveDebounce, false );

	// Use the 'passive: true' option when binding the scroll handler.
	// Browsers without EventListenerOptions support will expect a
	// boolean 'useCapture' argument in that position, and will cast
	// the object to a value of 'true'. This is harmless here.
	window.addEventListener( 'scroll', setActiveDebounce, {
		passive: true,
		capture: false
	} );

	// Start algorithm
	onVisibilitychange();
}

/**
 * Handle 'sessionReset' and 'sessionTick' events from mw.track()
 */
function instrument() {
	var tickCount = 'wmE-sessionTickTickCount';

	mw.trackSubscribe( 'sessionReset', function () {
		mw.cookie.set( tickCount, 0 );
	} );

	mw.trackSubscribe( 'sessionTick', function ( _, n ) {
		var count = ( Number( mw.cookie.get( tickCount ) ) || 0 );

		if ( n > tickLimit ) {
			throw new Error( 'Session ticks exceed limit' );
		}

		mw.cookie.set( tickCount, count + n );

		while ( n-- > 0 ) {
			mw.eventLog.submit( 'mediawiki.client.session_tick', {
				$schema: '/analytics/session_tick/2.0.0',
				tick: count + n,
				test: !supportsLocalStorage ? { supportsLocalStorage: 0 } : undefined
			} );
		}
	} );
}

//
// If the module has been enabled, and the browser supports the
// Page Visibility API.
//
if ( enabled && document.hidden !== undefined ) {

	// Sets the 'supportsPassive' flag.
	detectPassiveEventListenerSupport();

	// Sets the 'supportsLocalStorage' flag.
	detectLocalStorageSupport();

	// Only enable for browsers that support passive event listeners.
	// See: T274264, T248987//
	if ( supportsPassive === 1 ) {
		//
		// Prevents the cookie I/O along these code paths from
		// slowing down the initial paint and other critical
		// tasks at page load.
		//
		mw.requestIdleCallback( function () {
			regulator();
			instrument();
		} );
	}
}
