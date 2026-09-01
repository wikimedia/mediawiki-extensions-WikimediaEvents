/**
 * For T433066, adding a Test Kitchen event to simulate an experiment where exposure happens
 * after a registered user with an email address on file clicks on Edit link/button.
 *
 * This is for a Test Kitchen test and is likely obsolete/removable after 2027-01-15
 */

( function () {
	const editLink = document.getElementById( 'ca-edit' );
	if ( editLink && mw.config.get( 'wgWMEUserEligibleForEmailExperiment' ) ) {
		editLink.addEventListener( 'click', async () => {
			const e = await mw.tk.getExperiment( 'email-confirmation-enforcement-delayed-pilot' );
			e.sendExposure();
		} );
	}
}() );
