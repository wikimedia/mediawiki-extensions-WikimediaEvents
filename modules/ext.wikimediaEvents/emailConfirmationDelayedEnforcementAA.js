/**
 * For T433066, adding a Test Kitchen event to simulate an experiment where exposure happens
 * after a registered user with an email address on file clicks on Edit link/button.
 *
 * This is for a Test Kitchen test and is likely obsolete/removable after 2027-01-15
 */

( function () {
	const hasEmail = mw.config.get( 'wgWMEUserHasEmail' );
	const emailConfirmed = mw.config.get( 'wgWMEUserEmailConfirmed' );
	const isBot = mw.config.get( 'wgWMEUserIsBot' );
	const editLink = document.getElementById( 'ca-edit' );
	const userRegistration = mw.config.get( 'wgUserRegistration' );
	if ( editLink &&
		hasEmail &&
		!emailConfirmed &&
		!isBot &&
		userRegistration > 1788480000000 // 2026-09-04 00:00
	) {
		editLink.addEventListener( 'click', async () => {
			const e = await mw.tk.getExperiment( 'email-confirmation-enforcement-delayed-pilot' );
			e.sendExposure();
		} );
	}
}() );
