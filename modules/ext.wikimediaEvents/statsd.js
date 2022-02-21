/*!
 * mw.track subscribers for statsd timers and counters.
 *
 * Track events of the form mw.track( 'timing.foo', 1234.56 ); are logged as foo=1235ms
 * The time is assumed to be in milliseconds and is rounded to the nearest integer.
 *
 * Track events of the form mw.track( 'counter.bar', 5 ); are logged as bar=5c
 * The shorthand mw.track( 'counter.baz' ); is equivalent to mw.track( 'counter.baz', 1 );
 *
 * Track events of the form mw.track( 'gauge.baz', 42 ); are logged as baz=42g.
 * The value is assumed to be an integer (and rounded if not).
 *
 * $wgWMEStatsdBaseUri must point to a URL that accepts query strings,
 * such as `?foo=1235ms&bar=5c&baz=42g`.
 */
var queue = [];
var batchSize = 50;
var baseUrl = require( './config.json' ).statsdBaseUri;

// Statsv not configured
if ( !baseUrl ) {
	// Do nothing
	return;
}

function flush() {
	var values;

	while ( queue.length ) {
		values = queue.splice( 0, batchSize );
		mw.eventLog.sendBeacon( baseUrl + '?' + values.join( '&' ) );
	}
}

function enqueue( k, v ) {
	queue.push( k + '=' + v );
	// if the queue was empty, this was the first call to enqueue since
	// the beginning or a flush, so enqueue another flush
	if ( queue.length === 1 ) {
		mw.eventLog.enqueue( flush );
	}
}

mw.trackSubscribe( 'timing.', function ( topic, time ) {
	enqueue(
		topic.slice( 'timing.'.length ),
		Math.round( time ) + 'ms'
	);
} );

mw.trackSubscribe( 'counter.', function ( topic, count ) {
	count = Math.round( count );
	if ( isNaN( count ) ) {
		count = 1;
	}
	enqueue(
		topic.slice( 'counter.'.length ),
		count + 'c'
	);
} );

mw.trackSubscribe( 'gauge.', function ( topic, value ) {
	value = Math.round( value );
	if ( isNaN( value ) ) {
		return;
	}
	enqueue(
		topic.slice( 'gauge.'.length ),
		value + 'g'
	);
} );
