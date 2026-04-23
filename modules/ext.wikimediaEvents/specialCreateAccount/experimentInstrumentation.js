const CREATE_ACCOUNT_FORM_V1_EXPERIMENT_NAME = 'we-1-8-account-creation-form-v1';

function setupWe18V1ExperimentInstrumentation() {

	if ( !mw.config.get( 'wgMFMode' ) ) {
		// For now, this is mobile-only.
		// Though it should be expanded once there is a use-case for desktop as well.
		return;
	}

	if ( mw.config.get( 'wgDBname' ) !== 'enwiki' ) {
		// This is enabled on the auth.wikimedia.org domain,
		// so we have to check for the database ourselves.
		return;
	}

	if ( !mw.user.isAnon() ) {
		return;
	}

	if ( !mw.testKitchen ) {
		return;
	}

	const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.testKitchen' );
	const experiment = mw.testKitchen.compat.getExperiment( CREATE_ACCOUNT_FORM_V1_EXPERIMENT_NAME );

	if ( !document.querySelector( 'form#userlogin2' ) ) {
		// This might happen if account creations is attempted from via the TOR browser or similar
		experiment.send( 'page_visit_without_form', {
			action_source: 'Special:CreateAccount'
		} );
		return;
	}

	experiment.sendExposure();
	experiment.send( 'page_visit', {
		action_source: 'Special:CreateAccount'
	} );

	if ( document.querySelectorAll( '.cdx-message--error' ).length > 0 ) {
		experiment.send( 'page_visit_with_error', {
			action_source: 'Special:CreateAccount'
		} );
	}

	const treatmentGroupSelector = '#userlogin2 .mw-userlogin-username a.username-learn-more-link';
	const controlGroupSelector = '#userlogin2 .mw-userlogin-username label a';
	ClickThroughRateInstrument.start(
		`${ treatmentGroupSelector }, ${ controlGroupSelector }`, 'username policy link', experiment
	);

	const createAccountButtonSelector = '#wpCreateaccount';
	ClickThroughRateInstrument.start(
		createAccountButtonSelector, 'create account button', experiment
	);

	// eslint-disable-next-line no-jquery/no-global-selector
	$( '#userlogin2' ).on( 'submit', ( event ) => {

		experiment.send( 'creation_attempt', {
			action_source: 'Special:CreateAccount'
		} );

		const email = $( event.currentTarget ).find( '#wpEmail' ).val();
		if ( email ) {
			experiment.send( 'creation_attempt_with_email', {
				action_source: 'Special:CreateAccount'
			} );
		}
	} );
}

module.exports = setupWe18V1ExperimentInstrumentation;
