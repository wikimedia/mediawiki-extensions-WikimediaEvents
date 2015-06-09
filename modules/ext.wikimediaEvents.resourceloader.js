/**
 * Track denied ResourceLoader module requests
 * @see https://meta.wikimedia.org/wiki/Schema:ModuleLoadFailure
 * @see https://phabricator.wikimedia.org/T101806
 */
( function ( mw ) {

	// Filter: Logged-in users
	if ( !mw.config.get( 'wgUserName' ) ) {
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
