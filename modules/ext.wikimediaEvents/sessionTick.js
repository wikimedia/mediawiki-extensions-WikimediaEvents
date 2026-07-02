/**
 * Determine the length of a user session.
 *
 * Given an origin, a session is the longest interval during which, for any
 * N minute subinterval, at least one page from the origin is active.
 *
 * Here, this value N is RESET_MS.
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
const moduleConfig = require( './config.json' );
const enabled = moduleConfig.WMESessionTick;

// Milliseconds between ticks
const TICK_MS = 60000;
// Milliseconds before the page tries to idle and check for activity.
const IDLE_MS = 100000;
// Milliseconds after the page has become idle when the session will be reset.
const RESET_MS = 1800000;
// Milliseconds before an activity will be recorded again.
const DEBOUNCE_MS = 5000;
// Should represent the most ticks that could be sent at once
const TICK_LIMIT = Math.ceil( RESET_MS / TICK_MS );

const KEY_LAST_TIME = 'wmE-sessionTickLastTickTime';
const KEY_COUNT = 'wmE-sessionTickTickCount';

const FEATURE_NOT_AVAILABLE_STREAM = 'product_metrics.web_base_with_ip';
const FEATURE_NOT_AVAILABLE_SCHEMA_ID = '/analytics/product_metrics/web/base_with_ip/2.0.0';
const FEATURE_NOT_AVAILABLE_ACTION = 'feature_not_available';

/**
 * Detect support for EventListenerOptions and set 'supportsPassive' flag.
 * See: https://dom.spec.whatwg.org/#dictdef-addeventlisteneroptions
 */
function detectPassiveEventListenerSupport() {
	let supportsPassive = false;
	const noop = function () {};
	try {
		const options = Object.defineProperty( {}, 'passive', {
			get: function () {
				supportsPassive = true;
				return false;
			}
		} );
		window.addEventListener( 'testPassiveOption', noop, options );
		window.removeEventListener( 'testPassiveOption', noop, options );
	} catch ( e ) {
		logFeatureNotAvailableEvent( 'passiveEventListener' );
	}
	return supportsPassive;
}

/**
 * Publish 'sessionReset' event to mw.track().
 *
 * This allows EventLogging to periodically reset the
 * value returned by `mw.eventLog.id.getSessionId`, which
 * other events make use of.
 */
function sessionReset() {
	mw.storage.set( KEY_COUNT, 0 );
	mw.track( 'sessionReset', 1 );
}

function sessionTick( incr ) {
	if ( incr > TICK_LIMIT ) {
		throw new Error( 'Session ticks exceed limit' );
	}

	const count = ( Number( mw.storage.get( KEY_COUNT ) ) || 0 );
	mw.storage.set( KEY_COUNT, count + incr );

	while ( incr-- > 0 ) {
		mw.eventLog.submit( 'mediawiki.client.session_tick', {
			$schema: '/analytics/session_tick/2.0.0',
			tick: count + incr
		} );
	}
}

/**
 * Checks whether localStorage is available
 * https://developer.mozilla.org/en-US/docs/Web/API/Web_Storage_API/Using_the_Web_Storage_API#testing_for_availability
 *
 * Also, if not available, an event will be submitted with the specific reason
 *
 * @return whether or not localStorage is supported
 */
function detectLocalStorageSupport() {
	try {
		const testItem = '__localStorage_test__';
		localStorage.setItem( testItem, testItem );
		localStorage.removeItem( testItem );
		return true;
	} catch ( error ) {
		let context = 'localStorage';
		if ( error instanceof DOMException && error.name === 'QuotaExceededError' ) {
			context = 'localStorage full';
		}
		logFeatureNotAvailableEvent( context );
		return false;
	}
}

/**
 * Logs an event related to some feature that is not available
 * @param context the value for the `action_context` field
 */
function logFeatureNotAvailableEvent( context ) {
	const isMobileFrontendActive = mw.config.get( 'wgMFMode' ) !== null;
	const event = {
		$schema: FEATURE_NOT_AVAILABLE_SCHEMA_ID,
		action: FEATURE_NOT_AVAILABLE_ACTION,
		action_context: context,
		agent: {
			client_platform: 'mediawiki_js',
			client_platform_family: isMobileFrontendActive ? 'mobile_browser' : 'desktop_browser',
			ua_string: navigator.userAgent
		},
		performer: {
			session_id: mw.user.sessionId(),
			is_logged_in: !mw.user.isAnon(),
			is_temp: mw.user.isTemp()
		}
	};

	mw.eventLog.submit( FEATURE_NOT_AVAILABLE_STREAM, event );
}

/**
 * How the instrumentation works:
 *
 * 1. Listen for activity, such as click and scroll events.
 *
 * 2. Upon any activity, ignore the next 5 seconds (DEBOUNCE_MS),
 *    and then call run().
 *
 * 3. In run(), we read data from localStorage (which can be changed by other tabs),
 *    and if it's our first time here, or if it's been more than 1 minute (TICK_MS),
 *    we update the data and send an event beacon to the server.
 *
 * Generally, this means we read data at most once every 5 seconds,
 * and save data at most once every 1 minute.
 */
function regulator() {
	let tickTimeout = null;
	let idleTimeout = null;
	let debounceTimeout = null;

	function run() {
		const now = Date.now();
		const gap = now - ( Number( mw.storage.get( KEY_LAST_TIME ) ) || 0 );

		if ( gap > RESET_MS ) {
			mw.storage.set( KEY_LAST_TIME, now );
			sessionReset();
			// Tick once to start
			sessionTick( 1 );
		} else if ( gap > TICK_MS ) {
			mw.storage.set( KEY_LAST_TIME, now - ( gap % TICK_MS ) );
			sessionTick( Math.floor( gap / TICK_MS ) );
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
			debounceTimeout = setTimeout( () => {
				clearTimeout( debounceTimeout );
				debounceTimeout = null;
			}, DEBOUNCE_MS );

			// Optimization: Avoid hurting mouse/keyboard responsiveness.
			// This is called from event handlers. Delay setActive and its
			// (relatively) expensive storage I/O cost until the next frame.
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

// Only enable when:
// - the feature is enabled,
// - the browser supports the Page Visibility API,
// - the browser supports passive event listeners (T274264, T248987).
// - the browser supports localStorage (T413296)
if (
	enabled &&
	document.hidden !== undefined &&
	detectPassiveEventListenerSupport() &&
	detectLocalStorageSupport()
) {
	// Optimization: Avoid slowing down initial paint and page load time.
	// Delay storage I/O cost during module execution.
	mw.requestIdleCallback( regulator );
}
