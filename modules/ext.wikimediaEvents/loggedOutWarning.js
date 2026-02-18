const EXPERIMENT_NAME = 'growthexperiments-editattempt-anonwarning';
const SCHEMA_NAME = '/analytics/product_metrics/web/base/1.5.0';
const STREAM_NAME = 'mediawiki.product_metrics.contributors.experiments';

function setupLoggedOutWarningInstrumentation() {
	// Experiment is only for MobileFrontend enabled sites
	if ( mw.config.get( 'wgMFMode' ) === null ) {
		return;
	}
	// Experiment is only for anon users
	if ( !mw.user.isAnon() ) {
		return;
	}
	const experimentPromise = mw.loader.using( [
		'ext.testKitchen',
		'ext.wikimediaEvents.testKitchen'
	] ).then( () => {
		const experiment = mw.testKitchen.getExperiment( EXPERIMENT_NAME );
		experiment.setSchema( SCHEMA_NAME );
		experiment.setStream( STREAM_NAME );
		return experiment;
	} ).catch( ( error ) => {
		mw.log( 'Error loading ext.testKitchen module:', error );
		return null;
	} );

	mw.hook( 've.newTarget' ).add( ( target ) => {
		if ( target.constructor.static.trackingName !== 'mobile' ) {
			return;
		}
		target.overlay.on( 'editor-loaded', () => {
			experimentPromise.then( ( exp ) => {
				if ( !( exp && exp.isAssignedGroup( 'control', 'treatment' ) ) ) {
					return;
				}
				exp.send( 'experiment_exposure' );

				const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.testKitchen' );

				ClickThroughRateInstrument.start( '.actions > a.signup', 'Sign up', exp );
				ClickThroughRateInstrument.start( '.actions > a.login', 'Log in', exp );
				ClickThroughRateInstrument.start( '.actions > a.anonymous', 'Anon editing', exp );
				ClickThroughRateInstrument.start( '.anon-msg > * > a, .anon-msg > a', 'Temp account info', exp );
				ClickThroughRateInstrument.start(
					'.ve-ui-toolbar-group-back > .oo-ui-toolGroup-tools > * > a',
					'Close button',
					exp
				);

			} );
		} );
	} );
}

module.exports = exports = setupLoggedOutWarningInstrumentation;
