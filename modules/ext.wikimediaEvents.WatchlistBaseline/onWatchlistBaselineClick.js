const INSTRUMENT_NAME = 'WatchlistClickTracker';
const MIN_WATCHLIST_ITEMS = 1;

const specialPageName = mw.config.get( 'wgCanonicalSpecialPageName' );
if ( specialPageName === 'Watchlist' ) {
	mw.loader.using( 'ext.xLab' ).then( () => {
		const instrument = mw.eventLog.newInstrument( INSTRUMENT_NAME, 'mediawiki.product_metrics.WatchlistClickTracker', '/analytics/product_metrics/web/base/1.4.3' );
		const lines = document.querySelectorAll( '.mw-changeslist-line-inner' );

		if ( lines.length >= MIN_WATCHLIST_ITEMS ) {
			// Log that the user visited the watchlist page
			instrument.submitInteraction( 'page-visited', {
				action_source: 'Watchlist'
			} );
			lines.forEach( ( line ) => {
				const lineElements = {};

				// Select all the required links for tracking
				const articleLink = line.querySelector( 'a.mw-changeslist-title' );
				const diffLink = line.querySelector( 'a.mw-changeslist-diff' );
				const histLink = line.querySelector( 'a.mw-changeslist-history' );
				const userLink = line.querySelector( 'a.mw-userlink' );
				const userTalkLink = line.querySelector( 'a.mw-usertoollinks-talk' );
				const userContribsLink = line.querySelector( 'a.mw-usertoollinks-contribs' );
				const userBlockLink = line.querySelector( 'a.mw-usertoollinks-block' );
				const userRollbackLink = line.querySelector( '.mw-rollback-link a' );

				Object.assign( lineElements, {
					articleLink,
					diffLink,
					histLink,
					userLink,
					userTalkLink,
					userContribsLink,
					userBlockLink,
					userRollbackLink
				} );

				// Loop over these and add an event listener
				Object.keys( lineElements ).forEach( ( key ) => {
					const link = lineElements[ key ];
					if ( link ) {
						link.addEventListener( 'click', () => {
							// Submit the event
							instrument.submitClick(
								{
									action_source: 'Watchlist',
									action_context: key,
									instrument_name: INSTRUMENT_NAME
								}
							);
						} );
					}
				} );
			}
			);
		}
	}, () => {
	// noop if module not found
	} );
}
