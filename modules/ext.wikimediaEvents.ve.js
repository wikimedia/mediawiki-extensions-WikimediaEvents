/**
 * Track VisualEditor events.
 * @see https://meta.wikimedia.org/wiki/Schema:VisualEditorDOMRetrieved
 */
( function ( mw, $ ) {

	function titleCase( s ) {
		return s.charAt(0).toUpperCase() + s.slice(1);
	}

	/**
	 * Parse X-Parsoid-Performance header
	 *
	 * @param {string} Raw 'X-Parsoid-Performance' header
	 * @return {Object} Object encoding header values as object properties.
	 */
	function parseXpp( xpp ) {
		var parsed = {};
		xpp.replace( /([^;= ]+)=([^;]+)/g, function ( match, k, v ) {
			k = 'parsoid' + $.map( k.split('-'), titleCase ).join('');
			v = !isNaN( v ) ? +v : v === 'true' ? true : v === 'false' ? false : v;
			parsed[k] = v;
		} );
		return parsed;
	}

	mw.hook( 've.activationComplete' ).add( function () {
		ve.trackRegisterHandler( function ( topic, data ) {
			var event;

			// TODO: Replace this with a nicer pub/sub wrapper.
			if ( topic !== 'DOM retrieved' ) {
				return;
			}

			event = {
				bytes: data.bytes,
				cacheHit: data.cacheHit,
				duration: data.duration,
				pageId: mw.config.get( 'wgArticleId' ),
				revId: mw.config.get( 'wgCurRevisionId' )
			};

			if ( data.parsoid ) {
				$.extend( event, parseXpp( data.parsoid ) );
			}

			mw.eventLog.logEvent( 'VisualEditorDOMRetrieved', event );
		} );
	} );

}( mediaWiki, jQuery ) );
