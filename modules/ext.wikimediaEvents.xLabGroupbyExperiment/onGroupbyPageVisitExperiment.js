// run after togglewidget is initialized
mw.hook( 'rcfilters.groupbytogglewidget.initialized' ).add( () => {
	// load ext.xLab module as a soft dependency
	mw.loader.using( 'ext.xLab' ).then( () => {
		const experiment = mw.xLab.getExperiment( 'fy24-25-we-1-7-rc-grouping-toggle' );
		const specialPageName = mw.config.get( 'wgCanonicalSpecialPageName' );
		// Match special page name to the action source that automated analytics expects
		const actionSource = () => {
			if ( specialPageName === 'Recentchanges' ) {
				return 'RecentChanges';
			} else if ( specialPageName === 'Recentchangeslinked' ) {
				return 'RelatedChanges';
			}
			return specialPageName;
		};
		const hasMinimumWatchlistItems = specialPageName !== 'Watchlist' ? true : document.querySelectorAll( '.mw-changeslist-line' ).length >= 10;
		// Add click experiment CTR handlers on model update(implies list has changed)
		if ( hasMinimumWatchlistItems && experiment.isAssignedGroup( 'control', 'toggle-shown' ) ) {
			const isGrouped = document.querySelector( '.mw-rcfilters-ui-changesLimitPopupWidget input[type="checkbox"]' ).checked ? 'y' : 'n';
			const hasChanges = document.querySelectorAll( '.mw-changeslist-line' ).length > 0 ? 'y' : 'n';
			const hasShowIpButton = document.querySelectorAll( '.ext-checkuser-tempaccount-reveal-ip-button' ).length > 0 ? 'y' : 'n';
			const hasBlockButton = document.querySelectorAll( '.mw-usertoollinks-block' ).length > 0 ? 'y' : 'n';
			const hasRollbackButton = document.querySelectorAll( '.mw-rollback-link' ).length > 0 ? 'y' : 'n';
			// send page-visited impression event to the experiment
			experiment.send( 'page-visited', {
				instrument_name: 'RCClickTracker',
				action_source: actionSource(),
				action_context: JSON.stringify( {
					g: isGrouped,
					hc: hasChanges,
					sip: hasShowIpButton,
					b: hasBlockButton,
					rb: hasRollbackButton
				} )
			} );
		}
	}, () => {
		// noop if module not found
	} );
} );
