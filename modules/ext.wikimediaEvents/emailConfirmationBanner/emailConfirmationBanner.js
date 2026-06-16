/* eslint-disable camelcase */
// Long-term product-health instrumentation for the email confirmation banner.
// Sends impression and click events to the email-confirmation-banner-2026-06 Test Kitchen
// instrument. The matching server-side lifecycle events (email_confirmed, email_invalidated) are
// sent from WikimediaEventsHooks. Safely no-ops when Test Kitchen is unavailable.
const banner = document.querySelector( '.mw-emailconfirmbanner' );
if ( !banner || !mw.testKitchen || banner.dataset.tkInitialized ) {
	return;
}
banner.dataset.tkInitialized = '1';

const instrument = mw.testKitchen.getInstrument( 'email-confirmation-banner-2026-06' );

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
