const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.xLab' );
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

function instrumentDiffLinks( instrument ) {
	const diffLinkCards = document.querySelectorAll( '.ext-personal-dashboard-moderation-card' );
	for ( let i = 0; i < diffLinkCards.length; i++ ) {
		ClickThroughRateInstrument.start(
			'.ext-personal-dashboard-moderation-card:nth-of-type(' + ( i + 1 ) + ')',
			'Personal Dashboard diff link',
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
			ClickThroughRateInstrument.start(
				'#personal-dashboard-go-to-recentchanges',
				'Go to Recent Changes link',
				instrument
			);
		} );

		mw.hook( 'personaldashboard.recentactivity.listcard.loaded' ).add( () => {
			instrumentDiffLinks( instrument );
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

mw.loader.using( 'ext.testKitchen' ).then( () => {
	const instrument = mw.testKitchen.getInstrument( 'personal-dashboard-health-metrics' );
	const skin = mw.config.get( 'skin' );
	const selector = skin === 'minerva' ? '#p-personal' : '#pt-personaldashboard';
	ClickThroughRateInstrument.start(
		selector,
		'Personal Dashboard menu link',
		instrument
	);
} );
