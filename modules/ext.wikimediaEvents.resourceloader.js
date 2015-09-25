/*!
 * Track denied ResourceLoader module requests
 *
 * @see https://meta.wikimedia.org/wiki/Schema:ModuleLoadFailure
 * @see https://phabricator.wikimedia.org/T101806
 */
( function ( mw ) {
	function oneIn( populationSize ) {
		return Math.floor( Math.random() * populationSize ) === 0;
	}

	// Filter: Unsampled logged-in users
	// Filter: Sampled logged-out users
	if ( !mw.config.get( 'wgUserName' ) && !oneIn( 100 ) ) {
		return;
	}

	mw.trackSubscribe( 'resourceloader.forbidden', function ( topic, data ) {
		mw.loader.using( [ 'schema.ModuleLoadFailure' ], function () {
			mw.eventLog.logEvent( 'ModuleLoadFailure', {
				module: data.module,
				error: 'forbidden',
				request: data.request
			} );
		} );
	} );
}( mediaWiki ) );
