/**
 * Track usage of deprecated JavaScript functionality
 * @see https://meta.wikimedia.org/wiki/Schema:DeprecatedUsage
 */
( function ( mw ) {
	function oneIn( populationSize ) {
		return Math.floor( Math.random() * populationSize ) === 0;
	}

	if ( !oneIn( 100 ) ) {
		return;
	}

	mw.trackSubscribe( 'mw.deprecate', function ( topic, deprecated ) {
		mw.loader.using( [ 'mediawiki.inspect', 'schema.DeprecatedUsage' ], function () {
			mw.eventLog.logEvent( 'DeprecatedUsage', {
				method: deprecated,
				pageId: mw.config.get( 'wgArticleId' ),
				revId: mw.config.get( 'wgCurRevisionId' ),
				version: mw.config.get( 'wgVersion' ),
				modules: mw.inspect.grep( deprecated ).join(',')
			} );
		} );
	} );
}( mediaWiki ) );
