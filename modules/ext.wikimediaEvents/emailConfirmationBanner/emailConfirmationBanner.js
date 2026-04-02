mw.hook( 'mediawiki.emailConfirmationBanner.shown' ).add( ( container ) => {
	if ( !mw.testKitchen ) {
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

	const experiment = mw.testKitchen.getExperiment( 'email_confirmation_banner_ab_test' );
	if ( !experiment ) {
		return;
	}

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
