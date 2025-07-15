// run after togglewidget is initialized
mw.hook( 'rcfilters.groupbytogglewidget.initialized' ).add( () => {
	// load ext.xLab module as a soft dependency
	mw.loader.using( 'ext.xLab' ).then( () => {
		const experiment = mw.xLab.getExperiment( 'fy24-25-we-1-7-rc-grouping-toggle' );
		const actionSource = mw.config.get( 'wgCanonicalSpecialPageName' );
		const hasMinimumWatchlistItems = actionSource !== 'Watchlist' ? true : document.querySelectorAll( '.mw-changeslist-line' ).length >= 10;
		if ( hasMinimumWatchlistItems && experiment.isAssignedGroup( 'toggle-shown' ) ) {
			// show toggle
			// eslint-disable-next-line no-jquery/no-global-selector
			const $toggle = $( '.mw-xlab-experiment-fy24-25-we-1-7-rc-grouping-toggle-control' );
			$toggle.addClass( 'mw-xlab-experiment-fy24-25-we-1-7-rc-grouping-toggle-show' );
			$toggle.removeClass( 'mw-xlab-experiment-fy24-25-we-1-7-rc-grouping-toggle-control' );
			// instrument toggle clicks
			mw.hook( 'rcfilters.groupbytogglewidget.click' ).add( () => {
				// send a preference-change action to the experiment
				const actionSubtype = ( event.target.ariaChecked === 'true' ) ? 'on' : 'off';
				experiment.send( 'preference-change', {
					action_subtype: actionSubtype,
					action_context: JSON.stringify( { location: 'toggle' } )
				} );
			} );
			// instrument checkbox clicks
			mw.hook( 'rcfilters.groupbycheckboxwidget.click' ).add( () => {
				// send a preference-change action to the experiment
				const actionSubtype = ( event.target.checked === true ) ? 'on' : 'off';
				experiment.send( 'preference-change', {
					action_subtype: actionSubtype,
					action_context: JSON.stringify( { location: 'widget' } )
				} );
			} );
		}
	}, () => {
		// noop if module not found
	} );
} );
