/**
 * Track VisualEditor events.
 * @see https://meta.wikimedia.org/wiki/Schema:VisualEditorDOMRetrieved
 */
/*global ve*/

( function ( mw, $ ) {

	/** @var {Object} schemas Map of ve.track topics to EventLogging schemas. **/
	var schemas = {
		'performance.domLoad': 'VisualEditorDOMRetrieved',
		'performance.domSave': 'VisualEditorDOMSaved'
	};

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


	if ( !ve.trackSubscribe ) {
		return;
	}

	ve.trackSubscribe( 'performance', function ( topic, data ) {
		var event, schema = schemas[topic];

		if ( !schema ) {
			return;
		}

		event = {
			bytes: data.bytes,
			duration: data.duration,
			pageId: mw.config.get( 'wgArticleId' ),
			revId: mw.config.get( 'wgCurRevisionId' )
		};

		if ( data.hasOwnProperty( 'cacheHit' ) ) {
			event.cacheHit = data.cacheHit;
		}

		if ( data.parsoid ) {
			$.extend( event, parseXpp( data.parsoid ) );
		}

		mw.loader.using( 'schema.' + schema, function () {
			mw.eventLog.logEvent( schema, event );
		} );
	} );

}( mediaWiki, jQuery ) );
