mw.loader.using( 'ext.testKitchen' ).then( () => {
	const experiment = mw.testKitchen.compat.getExperiment( 'ab-test-email-confirmation-banner' );
	if ( !experiment.isAssignedGroup( 'arm_a' ) ) {
		experiment.sendExposure();
	}
} );

mw.hook( 'mediawiki.emailConfirmationBanner.shown' ).add( ( container ) => {

	mw.loader.using( 'ext.testKitchen' ).then( () => {
		const experiment = mw.testKitchen.compat.getExperiment( 'ab-test-email-confirmation-banner' );
		if ( !experiment.isAssignedGroup( 'arm_a' ) ) {
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

		experiment.sendExposure();
		experiment.send( 'impression', {
			action_source: 'banner'
		} );

		const ctaLink = banner.querySelector( 'a[href*="Special:ConfirmEmail"]' );
		if ( ctaLink ) {
			ctaLink.addEventListener( 'click', () => {
				experiment.send( 'click', {
					action_source: 'banner',
					action_context: 'confirm_email'
				} );
			}, { once: true } );
		}

	} );

} );
