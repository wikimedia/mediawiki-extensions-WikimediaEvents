/*!
 * mw.track subscribers for StatsD counters and timers.
 *
 * These do not support labels. Instead, labels are stored as unnamed
 * dot-separated portions inside the stat names. These are intended for
 * use with StatsD and Graphite.
 *
 * $wgWMEStatsdBaseUri must point to a URL that accepts query strings,
 * such as `?foo=1235ms&bar=5c&baz=42g`.
 *
 * ```js
 * mw.track( 'counter.foo.quux', 5 );
 * // logged as foo.quux=5c
 *
 * mw.track( 'counter.foo.quux' );
 * // Shorthand, equivalent to mw.track( 'counter.foo.quux', 1 )
 *
 * mw.track( 'timing.bar', 1234.56 );
 * // logged as bar=1235ms
 * // The time is assumed to be in milliseconds and is rounded to the nearest integer.
 *
 * mw.track( 'gauge.baz', 42 );
 * // logged as baz=42g
 * // The value is assumed to be an integer (and rounded if not).
 * ```
 */
const config = require( './config.json' );
const BATCH_SIZE = 5000;
let statsdBuffer = '';
let statsdFlushPending = false;

function statsdFlush() {
	mw.eventLog.sendBeacon( config.WMEStatsdBaseUri + '?' + statsdBuffer );
	statsdBuffer = '';
}

function statsdAdd( line ) {
	if ( config.WMEStatsdBaseUri ) {
		if ( statsdBuffer && ( statsdBuffer.length + line.length ) > BATCH_SIZE ) {
			statsdFlush();
		}
		statsdBuffer += ( statsdBuffer ? '&' : '' ) + line;
		if ( !statsdFlushPending ) {
			statsdFlushPending = true;
			mw.eventLog.enqueue( () => {
				statsdFlushPending = false;
				statsdFlush();
			} );
		}
	}
}

mw.trackSubscribe( 'timing.', ( topic, time ) => {
	statsdAdd( topic.slice( 'timing.'.length ) + '=' + Math.round( time ) + 'ms' );
} );

mw.trackSubscribe( 'counter.', ( topic, count ) => {
	count = isNaN( count ) ? 1 : Math.round( count );
	statsdAdd( topic.slice( 'counter.'.length ) + '=' + count + 'c' );
} );

mw.trackSubscribe( 'gauge.', ( topic, value ) => {
	if ( isNaN( value ) ) {
		return;
	}
	statsdAdd( topic.slice( 'gauge.'.length ) + '=' + Math.round( value ) + 'g' );
} );
