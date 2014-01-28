/**
 * Track VisualEditor timing data.
 * @see https://meta.wikimedia.org/wiki/Schema:TimingData
 */
( function ( mw ) {
	var timer = null, queue = [];

	function dispatch() {
		var points = [];
		while ( queue.length ) {
			points.push( queue.pop() );
		}
		mw.loader.using( 'schema.TimingData', function () {
			mw.eventLog.logEvent( 'TimingData', { points: points.join(',') } );
		} );
	}

	mw.trackSubscribe( 've.performance', function ( topic, data ) {
		if ( data.duration ) {
			queue.push( topic + '=' + Math.round( data.duration ) );
			clearTimeout( timer );
			timer = setTimeout( dispatch, 2000 );
		}
	} );
}( mediaWiki ) );
