/* eslint-disable camelcase */
const banner = document.querySelector( '.mw-emailconfirmbanner' );
if ( !banner || !mw.testKitchen ) {
	return;
}
if ( banner.dataset.tkInitialized ) {
	return;
}
banner.dataset.tkInitialized = '1';

const instrument = mw.testKitchen.getInstrument( 'email_confirmation_banner' );
if ( !instrument ) {
	return;
}

instrument.send( 'impression', {
	action_source: 'banner'
} );

const ctaLink = banner.querySelector( 'a[href*="Special:ConfirmEmail"]' );
if ( ctaLink ) {
	ctaLink.addEventListener( 'click', () => {
		instrument.send( 'click', {
			action_source: 'banner',
			action_context: 'confirm_email'
		} );
	}, { once: true } );
}
