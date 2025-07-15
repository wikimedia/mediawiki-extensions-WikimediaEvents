// run after changeslist is updated
mw.hook( 'rcfilters.changeslistwrapperwidget.updated' ).add( () => {
	// load ext.xLab module as a soft dependency
	mw.loader.using( 'ext.xLab' ).then( () => {
		const experiment = mw.xLab.getExperiment( 'fy24-25-we-1-7-rc-grouping-toggle' );
		const actionSource = mw.config.get( 'wgCanonicalSpecialPageName' );
		const hasMinimumWatchlistItems = actionSource !== 'Watchlist' ? true : document.querySelectorAll( '.mw-changeslist-line' ).length >= 10;
		// Add click experiment CTR handlers on model update(implies list has changed)
		if ( hasMinimumWatchlistItems && experiment.isAssignedGroup( 'control', 'toggle-shown' ) ) {
			// capturing CTR for any clickable element within the change list
			// eslint-disable-next-line no-jquery/no-sizzle,no-jquery/no-global-selector
			$( '.mw-changeslist-line:visible' ).on(
				'click',
				'a',
				( jqEvent ) => {
					let elementToCheck;
					if ( jqEvent.target instanceof HTMLAnchorElement ) {
						// grab class or href
						// map to type above
						elementToCheck = jqEvent.target;
					} else {
						// grab closest anchor and grab class or href
						elementToCheck = jqEvent.target.closest( 'a' );
					}
					let linkType;
					if ( elementToCheck.classList.contains( 'mw-changeslist-diff' ) || elementToCheck.classList.contains( 'mw-changeslist-groupdiff' ) ) {
						linkType = 'diff';
					}
					if ( elementToCheck.classList.contains( 'mw-changeslist-history' ) ) {
						linkType = 'page-history';
					}
					if ( elementToCheck.classList.contains( 'mw-userlink' ) ) {
						linkType = 'user-page';
					}
					if ( elementToCheck.classList.contains( 'mw-usertoollinks-talk' ) ) {
						linkType = 'user-talk-page';
					}
					if ( elementToCheck.classList.contains( 'mw-usertoollinks-contribs' ) ) {
						linkType = 'contribs-page';
					}
					if ( elementToCheck.classList.contains( 'mw-thanks-thank-link' ) ) {
						linkType = 'thank';
					}
					if ( elementToCheck.classList.contains( 'mw-usertoollinks-block' ) ) {
						linkType = 'block';
					}
					if ( elementToCheck.classList.contains( 'mw-changeslist-title' ) ) {
						linkType = 'page';
					}
					if ( linkType ) {
						// eslint-disable-next-line no-shadow
						const actionSource = mw.config.get( 'wgCanonicalSpecialPageName' );
						const isGrouped = document.querySelector( '.mw-rcfilters-ui-changesLimitPopupWidget input[type="checkbox"]' ).checked ? 'on' : 'off';
						experiment.send( 'click', {
							action_context: JSON.stringify( {
								grouping: isGrouped,
								link: linkType
							} ),
							instrument_name: 'RCClickTracker',
							action_source: actionSource
						} );
					}
				}
			);

			const showIpLinks = document.querySelectorAll( '.ext-checkuser-tempaccount-reveal-ip-button > a' );
			showIpLinks.forEach( ( link ) => {
				link.addEventListener( 'click', () => {
					// eslint-disable-next-line no-shadow
					const actionSource = mw.config.get( 'wgCanonicalSpecialPageName' );
					const isGrouped = document.querySelector( '.mw-rcfilters-ui-changesLimitPopupWidget input[type="checkbox"]' ).checked ? 'on' : 'off';
					experiment.send( 'click', {
						action_context: JSON.stringify( {
							grouping: isGrouped,
							link: 'show-ip'
						} ),
						instrument_name: 'RCClickTracker',
						action_source: actionSource
					} );
				} );
			} );
			const rollbackLinks = document.querySelectorAll( '.mw-rollback-link > a' );
			rollbackLinks.forEach( ( link ) => {
				link.addEventListener( 'click', () => {
					// eslint-disable-next-line no-shadow
					const actionSource = mw.config.get( 'wgCanonicalSpecialPageName' );
					const isGrouped = document.querySelector( '.mw-rcfilters-ui-changesLimitPopupWidget input[type="checkbox"]' ).checked ? 'on' : 'off';
					experiment.send( 'click', {
						action_context: JSON.stringify( {
							grouping: isGrouped,
							link: 'rollback'
						} ),
						instrument_name: 'RCClickTracker',
						action_source: actionSource
					} );
				} );
			} );
		}
	}, () => {
		// noop if module not found
	} );
} );
