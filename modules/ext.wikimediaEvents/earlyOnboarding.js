const { anyPageVisit, withContext } = require( 'ext.wikimediaEvents.testKitchen' );
const editSaved = require( './editSaved.js' );

const MOTIVATIONS = [ 'reading', 'editing', 'both', 'skipped', 'other' ];

// Keep in sync with GrowthExperiments' SpecialWelcomeSurvey::REASON_TO_MOTIVATION.
const REASON_TO_MOTIVATION = {
	read: 'reading',
	'edit-typo': 'editing',
	'edit-info-add-change': 'editing',
	'add-image': 'editing',
	'new-page': 'editing',
	'program-participant': 'editing',
	other: 'other'
};

/**
 * The control group gets the Welcome Survey instead of AccountSetup, so derive its
 * motivation from the survey answer, as its `welcome_survey_account_setup_motivation_saved`
 * event does.
 *
 * @return {string|undefined}
 */
function getWelcomeSurveyMotivation() {
	let responses;
	try {
		responses = JSON.parse( mw.user.options.get( 'welcomesurvey-responses' ) );
	} catch ( e ) {
		return undefined;
	}
	if ( !responses || typeof responses !== 'object' ) {
		return undefined;
	}
	// eslint-disable-next-line no-underscore-dangle
	if ( responses._skip ) {
		return 'skipped';
	}
	// Only the group is stored until the survey is submitted.
	// eslint-disable-next-line no-underscore-dangle
	if ( !responses._submit_date ) {
		return undefined;
	}
	if ( !responses.reason || responses.reason === 'placeholder' ) {
		return 'skipped';
	}
	return REASON_TO_MOTIVATION[ responses.reason ] || 'other';
}

const motivation = mw.user.options.get( 'growthexperiments-account-setup-motivation' ) ||
	getWelcomeSurveyMotivation();

mw.testKitchen.getExperiment( 'de-1-3-1-specialhomepage-onboarding-ab-test' ).then(
	( e ) => {
		// Record the visit's referrer class in the event's `action_source`.
		// Record also the motivation in its `action_context`: GrowthBook metrics read a single source
		// table, so both have to travel on the `page_visit` event itself.
		// Refer to 'anyPageVisit.js', and modules/ext.wikimediaEvents.testKitchen/helpers.js
		const extraContext = {};
		if ( MOTIVATIONS.includes( motivation ) ) {
			extraContext.user_motivation = motivation;
		}
		const anyPageVisitWithUserMotivation = withContext(
			anyPageVisit( { recordReferrerClass: true } ),
			extraContext
		);
		e.use( anyPageVisitWithUserMotivation );
		e.use( editSaved );
	}
);
