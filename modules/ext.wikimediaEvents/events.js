/*!
 * Alias `mw.track( 'wikimedia.event.foo' )` to `mw.track( 'event.foo' )`.
 *
 * This allows logging of events without making the logging code depend on
 * Wikimedia infrastrucutre: if WikimediaEvents is not installed, the event
 * will be ignored.
 */
( function () {
	/**
	 * Helper function to build the editCountBucket value
	 *
	 * @param {number} editCount
	 * @return {string}
	 */
	function getEditCountBucket( editCount ) {
		if ( editCount >= 1000 ) {
			return '1000+ edits';
		}
		if ( editCount >= 100 ) {
			return '100-999 edits';
		}
		if ( editCount >= 5 ) {
			return '5-99 edits';
		}
		if ( editCount >= 1 ) {
			return '1-4 edits';
		}
		return '0 edits';
	}

	mw.wikimediaEvents = {
		getEditCountBucket: getEditCountBucket
	};

	mw.trackSubscribe( 'wikimedia.event.', function ( topic, event ) {
		mw.track( topic.replace( /^wikimedia\./, '' ), event );
	} );
}() );
