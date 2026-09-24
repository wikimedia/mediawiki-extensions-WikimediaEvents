const { anyPageVisit, withContext } = require( 'ext.wikimediaEvents.testKitchen' );
const editSaved = require( './editSaved.js' );

const MOTIVATIONS = [ 'reading', 'editing', 'both', 'skipped' ];
const motivation = mw.user.options.get( 'growthexperiments-account-setup-motivation' );

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
