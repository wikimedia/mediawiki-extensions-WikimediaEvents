const accountCreated = require( './accountCreation/accountCreated.js' );

const FUNDRAISING_COOKIE = 'centralnotice_hide_fundraising';
const EXPERIMENT_NAME = 'donor-status-consent';

const experimentPromise = Promise.resolve(
	mw.testKitchen.getExperiment( EXPERIMENT_NAME )
);

// catch hooks from interacting with the experimental dialog
function setupInstrumentation( experiment ) {
	const donationCookie = mw.cookie.get( FUNDRAISING_COOKIE, '' );

	// if they're not a donor, assume they won't be shown the experiment
	if ( !donationCookie ) {
		return;
	}

	let donorInfo;

	try {
		donorInfo = JSON.parse( donationCookie );
	} catch ( e ) {
		return;
	}

	const donationDate = donorInfo.created ? new Date( donorInfo.created * 1000 ) : null;
	const daysSince = Math.floor( ( Date.now() - donationDate ) / ( 1000 * 60 * 60 * 24 ) );

	mw.hook( 'wikimediaCustomizations.donorAccountCreation.yes' ).add( () => {
		experiment.send( 'click', {
			action_subtype: 'yes',
			action_source: 'link_account_popup',
			action_context: { donation_cookie_days: daysSince }
		} );
	} );

	mw.hook( 'wikimediaCustomizations.donorAccountCreation.no' ).add( () => {
		experiment.send( 'click', {
			action_subtype: 'no',
			action_source: 'link_account_popup',
			action_context: { donation_cookie_days: daysSince }
		} );
	} );

	mw.hook( 'wikimediaCustomizations.donorAccountCreation.later' ).add( () => {
		experiment.send( 'click', {
			action_subtype: 'later',
			action_source: 'link_account_popup',
			action_context: { donation_cookie_days: daysSince }
		} );
	} );

	mw.hook( 'wikimediaCustomizations.donorAccountCreation.accountConnected' ).add( () => {
		accountCreated( experiment );
	} );
}

$( () => {
	experimentPromise.then( ( experiment ) => {
		// only fire if the user is in the experiment, since they could also have used a campaign
		if ( experiment && experiment.getAssignedGroup() ) {
			setupInstrumentation( experiment );
		}
	} );
} );
