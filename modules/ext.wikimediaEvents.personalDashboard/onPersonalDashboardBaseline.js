const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.testKitchen' );
const specialPageName = mw.config.get( 'wgCanonicalSpecialPageName' );

function instrumentReviewChangesLinks( instrument, selector ) {
	const links = document.querySelectorAll( selector );
	const friendlyNameDiff = 'Personal Dashboard diff link';
	const friendlyNameWatchlist = 'Personal Dashboard watched diff link';
	let i = 0;
	for ( const link of links ) {
		const origin = link.dataset.feedorigin;
		const friendlyName = origin === 'recentchanges' ?
			friendlyNameDiff : friendlyNameWatchlist;
		ClickThroughRateInstrument.start(
			selector + ':nth-of-type(' + ( i + 1 ) + ')',
			friendlyName,
			instrument
		);
		i++;
	}
}

if ( specialPageName === 'PersonalDashboard' ) {
	const instrument = mw.testKitchen.getInstrument(
		'personal-dashboard-health-metrics'
	);
	instrument.submitInteraction( 'pageview' );

	mw.hook( 'personaldashboard.recentactivity.loaded' ).add( () => {
		// Not all views include this link
		if ( document.querySelector( '#personal-dashboard-go-to-recentchanges' ) ) {
			ClickThroughRateInstrument.start(
				'#personal-dashboard-go-to-recentchanges',
				'Go to Recent Changes link',
				instrument
			);
		}
	} );

	mw.hook( 'personaldashboard.recentactivity.listcard.loaded' ).add( () => {
		instrumentReviewChangesLinks( instrument,
			'.personal-dashboard-review-changes__card' );
	} );
}
