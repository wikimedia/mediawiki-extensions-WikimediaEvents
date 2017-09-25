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
	var timer = null,
		queue = [],
		batchSize = 50,
		baseUrl = mw.config.get( 'wgWMEStatsdBaseUri' ),
		// Based on mw.eventLog.Core#sendBeacon
		sendBeacon = navigator.sendBeacon
			? function ( url ) { try { navigator.sendBeacon( url ); } catch ( e ) {} }
			: function ( url ) { ( new Image() ).src = url; };

	// Statsv not configured, or DNT enabled
	if ( !baseUrl || /1|yes/.test( navigator.doNotTrack ) ) {
		// Do nothing
		return;
	}

	function dispatch() {
		var i, values;
		timer = null;
		// Send events in batches
		// Note that queue is an array, not an object, because keys can be repeated
		while ( queue.length ) {
			// Ideally we'd use .map() here, but we have to support old browsers that don't have it
			values = queue.splice( 0, batchSize );
			for ( i = 0; i < values.length; i++ ) {
				values[ i ] = values[ i ].key + '=' + values[ i ].value;
			}
			sendBeacon( baseUrl + '?' + values.join( '&' ) );
		}
	}

	function schedule() {
		// Don't unconditionally re-create the timer as that may post-pone execution indefinitely
		// if different page components send metrics less than 2s apart before the user closes
		// their window. Instead, only re-delay execution if the queue is small.
		if ( !timer ) {
			timer = setTimeout( dispatch, 2000 );
		} else if ( queue.length < batchSize ) {
			clearTimeout( timer );
			timer = setTimeout( dispatch, 2000 );
		}
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
