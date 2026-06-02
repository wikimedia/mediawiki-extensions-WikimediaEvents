const experiment = mw.testKitchen.compat.getExperiment( 'ab-test-email-confirmation-banner' );
if ( !experiment.isAssignedGroup( 'arm_a' ) ) {
	experiment.sendExposure();
}

mw.hook( 'mediawiki.emailConfirmationBanner.shown' ).add( ( container ) => {

	const bannerExperiment = mw.testKitchen.compat.getExperiment( 'ab-test-email-confirmation-banner' );
	if ( !bannerExperiment.isAssignedGroup( 'arm_a' ) ) {
		return;
	}

	const banner = container.querySelector( '.mw-emailconfirmbanner' );
	if ( !banner ) {
		return;
	}
	if ( banner.dataset.tkInitialized ) {
		return;
	}
	banner.dataset.tkInitialized = '1';

	bannerExperiment.sendExposure();
	bannerExperiment.send( 'impression', {
		action_source: 'banner'
	} );

	const ctaLink = banner.querySelector( 'a[href*="Special:ConfirmEmail"]' );
	if ( ctaLink ) {
		ctaLink.addEventListener( 'click', () => {
			bannerExperiment.send( 'click', {
				action_source: 'banner',
				action_context: 'confirm_email'
			} );
		}, { once: true } );
	}

} );
