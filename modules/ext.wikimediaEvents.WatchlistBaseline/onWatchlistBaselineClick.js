const INSTRUMENT_NAME = 'watchlist-click-tracker';
const MIN_WATCHLIST_ITEMS = 1;

const specialPageName = mw.config.get( 'wgCanonicalSpecialPageName' );
if ( specialPageName === 'Watchlist' ) {
	mw.loader.using( 'ext.testKitchen' ).then( () => {
		const instrument = mw.testKitchen.getInstrument( INSTRUMENT_NAME );

		const lines = document.querySelectorAll( '.mw-changeslist-line-inner' );

		if ( lines.length >= MIN_WATCHLIST_ITEMS ) {
			// Log that the user visited the watchlist page
			instrument.send( 'page-visited', {
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
							instrument.send(
								'click',
								{
									action_source: 'Watchlist',
									action_context: key
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
