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
 * $wgWMEStatsdBaseUri must point to a URL that accepts query strings like ?foo=1235ms&bar=5c&baz=42g
 */

( function ( mw ) {
	var timer = null, queue = [];

	if ( !mw.config.get( 'wgWMEStatsdBaseUri' ) ) {
		// Not configured, do nothing
		return;
	}

	function dispatch() {
		var i, len, values;
		// Send events in batches of 50
		// Note that queue is an array, not an object, because keys can be repeated
		while ( queue.length ) {
			// Ideally we'd use .map() here, but we have to support old browsers that don't have it
			values = queue.splice( 0, 50 );
			for ( i = 0, len = values.length; i < len; i++ ) {
				values[i] = values[i].key + '=' + values[i].value;
			}
			( new Image() ).src = mw.config.get( 'wgWMEStatsdBaseUri' ) + '?' + values.join( '&' );
		}
	}

	function schedule() {
		if ( timer !== null ) {
			clearTimeout( timer );
		}
		// Save up events until no events occur for 2 seconds
		timer = setTimeout( dispatch, 2000 );
	}

	mw.trackSubscribe( 'timing.', function ( topic, time ) {
		queue.push( {
			key: topic.substring( 'timing.'.length ),
			value: Math.round( time ) + 'ms'
		} );
		schedule();
	} );

	mw.trackSubscribe( 'counter.', function ( topic, count ) {
		count = Math.round( count );
		if ( isNaN( count ) ) {
			count = 1;
		}
		queue.push( {
			key: topic.substring( 'counter.'.length ),
			value: count + 'c'
		} );
		schedule();
	} );

	mw.trackSubscribe( 'gauge.', function ( topic, value ) {
		value = Math.round( value );
		if ( isNaN( value ) ) {
			return;
		}
		queue.push( {
			key: topic.substring( 'gauge.'.length ),
			value: value + 'g'
		} );
		schedule();
	} );
}( mediaWiki ) );
