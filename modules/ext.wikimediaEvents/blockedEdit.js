/* global ve */

// visualeditor (visual and source)
mw.hook( 've.activationComplete' ).add( function () {
	if ( !ve.init.target.canEdit ) {
		// might be a block or just a protected page; the API will work that out
		( new mw.Api() ).post( {
			formatversion: 2,
			action: 'wikimediaeventsblockededit',
			page: mw.config.get( 'wgPageName' ),
			interface: 'visualeditor',
			platform: ve.getProp( ve.init.target, 'constructor', 'static', 'platformType' ) || 'other'
		} );
	}
} );

// mobilefrontend editors
mw.trackSubscribe( 'counter.MediaWiki.BlockNotices.' + mw.config.get( 'wgDBname' ) + '.MobileFrontend.shown', function () {
	( new mw.Api() ).post( {
		formatversion: 2,
		action: 'wikimediaeventsblockededit',
		page: mw.config.get( 'wgPageName' ),
		interface: 'mobilefrontend',
		platform: 'mobile'
	} );
} );

// discussiontools
mw.trackSubscribe( 'dt.commentSetupError', function ( topic, code ) {
	if ( code === 'permissions-error' ) {
		// might be a block or just a protected page; the API will work that out
		( new mw.Api() ).post( {
			formatversion: 2,
			action: 'wikimediaeventsblockededit',
			page: mw.config.get( 'wgPageName' ),
			interface: 'discussiontools',
			platform: mw.config.get( 'wgMFMode' ) !== null ? 'mobile' : 'desktop'
		} );
	}
} );
