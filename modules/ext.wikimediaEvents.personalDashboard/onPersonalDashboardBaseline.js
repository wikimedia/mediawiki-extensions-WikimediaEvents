const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.testKitchen' );
const specialPageName = mw.config.get( 'wgCanonicalSpecialPageName' );

function instrumentPolicyLinks( instrument ) {
	mw.hook( 'personaldashboard.policymodule.loaded' ).add( () => {
		ClickThroughRateInstrument.start(
			'#neutral-point-of-view',
			'Neutral point of view policy link',
			instrument
		);
		ClickThroughRateInstrument.start(
			'#no-original-research',
			'No original research policy link',
			instrument
		);
		ClickThroughRateInstrument.start(
			'#verifiability',
			'Verifiability policy link',
			instrument
		);
		ClickThroughRateInstrument.start(
			'#assume-good-faith',
			'Assume good faith policy link',
			instrument
		);
	} );
}

function instrumentLinks( instrument, selector, friendlyName ) {
	const links = document.querySelectorAll( selector );
	for ( let i = 0; i < links.length; i++ ) {
		ClickThroughRateInstrument.start(
			selector + ':nth-of-type(' + ( i + 1 ) + ')',
			friendlyName,
			instrument
		);
	}
}

if ( specialPageName === 'PersonalDashboard' ) {
	mw.loader.using( 'ext.testKitchen' ).then( () => {
		const instrument = mw.testKitchen.getInstrument( 'personal-dashboard-health-metrics' );
		instrument.submitInteraction( 'pageview' );
		instrumentPolicyLinks( instrument );

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
			instrumentLinks( instrument,
				'.ext-personal-dashboard-moderation-card',
				'Personal Dashboard diff link' );
		} );

		mw.hook( 'personaldashboard.activediscussions.listcard.loaded' ).add( () => {
			instrumentLinks(
				instrument,
				'.ext-personal-dashboard-active-discussion-card',
				'Personal Dashboard discussion link' );
		} );

		mw.hook( 'personaldashboard.impact.loaded' ).add( () => {
			ClickThroughRateInstrument.start(
				'#personal-dashboard-thanks-link',
				'Personal Dashboard thanks link',
				instrument
			);
		} );

	} );
}
