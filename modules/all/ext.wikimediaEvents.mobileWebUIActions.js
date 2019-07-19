/*!
 * Track mobile web ui interactions
 *
 * @see https://phabricator.wikimedia.org/T220016
 * @see https://meta.wikimedia.org/wiki/Schema:MobileWebUIActionsTracking
 */
( function ( config, user, mwExperiments, Schema ) {
	var schemaMobileWebUIActionsTracking;

	/**
	 * Helper function to build comma-separated list of all enabled mobile modes
	 * @return {string[]}
	 */
	function getModes() {
		var mode = config.get( 'wgMFMode', 'desktop' ), modes;
		modes = [ mode ];
		if ( mode !== 'desktop' && config.get( 'wgMFAmc', false ) ) {
			modes.push( 'amc' );
		}
		return modes;
	}

	/**
	 * Helper function to build the editCountBucket value
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
			name = element.getAttribute( 'data-event-name' );
		if ( name ) {
			schemaMobileWebUIActionsTracking.log( {
				action: 'click',
				name: name,
				sessionToken: user.sessionId(),
				destination: element.getAttribute( 'href' )
			} );
		}
	} );
}( mw.config, mw.user, mw.experiments, mw.eventLog.Schema ) );
