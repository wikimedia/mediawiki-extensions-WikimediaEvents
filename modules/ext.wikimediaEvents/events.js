/*!
 * Alias `mw.track( 'wikimedia.event.foo' )` to `mw.track( 'event.foo' )`.
 *
 * This allows logging of events without making the logging code depend on
 * Wikimedia infrastrucutre: if WikimediaEvents is not installed, the event
 * will be ignored.
 */
mw.trackSubscribe( 'wikimedia.event.', function ( topic, event ) {
	mw.track( topic.replace( /^wikimedia\./, '' ), event );
} );
