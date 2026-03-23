const CREATE_ACCOUNT_FORM_V1_EXPERIMENT_NAME = 'we-1-8-account-creation-form-v1';
const STREAM_NAME = 'mediawiki.product_metrics.contributors.experiments';

const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.testKitchen' );

function setupWe18V1ExperimentInstrumentation() {

	if ( !mw.config.get( 'wgMFMode' ) ) {
		// For now, this is mobile-only. Though it should be expanded once there is a use-case for desktop as well.
		return;
	}

	if ( !mw.user.isAnon() ) {
		return;
	}

	if ( !mw.testKitchen ) {
		return;
	}

	const experiment = mw.testKitchen.getExperiment( CREATE_ACCOUNT_FORM_V1_EXPERIMENT_NAME );
	experiment.setStream( STREAM_NAME );

	experiment.sendExposure();
	experiment.send( 'page_visit', {
		action_source: 'Special:CreateAccount'
	} );

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
		const email = $( event.currentTarget ).find( '#wpEmail' ).val();
		if ( email ) {
			experiment.send( 'creation_attempt_with_email', {
				action_source: 'Special:CreateAccount'
			} );
		}
	} );
}

module.exports = setupWe18V1ExperimentInstrumentation;
