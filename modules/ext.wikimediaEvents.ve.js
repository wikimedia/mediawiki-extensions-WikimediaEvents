/*global ve*/

/**
 * Track VisualEditor timing data.
 * @see https://meta.wikimedia.org/wiki/Schema:TimingData
 */
( function ( mw ) {
	var timer = null, queue = [];

	if ( !ve.trackSubscribe ) {
		return;
	}

	function dispatch() {
		var points = [];
		while ( queue.length ) {
			points.push( queue.pop() );
		}
		mw.loader.using( 'schema.TimingData', function () {
			mw.eventLog.logEvent( 'TimingData', { points: points.join(',') } );
		} );
	}

	ve.trackSubscribeAll( function ( topic, data ) {
		if ( data && data.duration ) {
			queue.push( 've.' + topic + '=' + Math.round( data.duration ) );
			clearTimeout( timer );
			timer = setTimeout( dispatch, 2000 );
		}
	} );
}( mediaWiki ) );
