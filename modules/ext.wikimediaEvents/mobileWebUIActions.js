/*!
 * Track mobile web ui interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T220016
 * Schema: https://meta.wikimedia.org/wiki/Schema:MobileWebUIActionsTracking
 */
( function ( config, user, mwExperiments, Schema ) {
	var schemaMobileWebUIActionsTracking,
		getEditCountBucket = mw.wikimediaEvents.getEditCountBucket;

	/**
	 * Helper function to build comma-separated list of all enabled mobile modes
	 *
	 * @return {string[]}
	 */
	function getModes() {
		var mode = config.get( 'wgMFMode', 'desktop' ),
			modes = [ mode ];
		if ( mode !== 'desktop' && config.get( 'wgMFAmc', false ) ) {
			modes.push( 'amc' );
		}
		return modes;
	}

	schemaMobileWebUIActionsTracking = new Schema( 'MobileWebUIActionsTracking',
		config.get( 'wgWMEMobileWebUIActionsTracking', 0 ),
		{
			isAnon: user.isAnon(),
			editCountBucket: getEditCountBucket( config.get( 'wgUserEditCount' ) ),
			modes: getModes().join( ',' )
		}
	);

	// eslint-disable-next-line no-jquery/no-global-selector
	$( 'body' ).on( 'click', function ( event ) {
		var element = event.target,
			name = element.getAttribute( 'data-event-name' ),
			analyticsEvent, destination;

		if ( name ) {
			destination = element.getAttribute( 'href' );
			analyticsEvent = {
				action: 'click',
				name: name,
				token: user.sessionId()
			};
			if ( destination ) {
				analyticsEvent.destination = destination;
			}
			schemaMobileWebUIActionsTracking.log( analyticsEvent );
		}
	} );
}( mw.config, mw.user, mw.experiments, mw.eventLog.Schema ) );
