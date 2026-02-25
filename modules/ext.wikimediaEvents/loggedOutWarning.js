const EXPERIMENT_NAME = 'growthexperiments-editattempt-anonwarning';
const SCHEMA_NAME = '/analytics/product_metrics/web/base/1.5.0';
const STREAM_NAME = 'mediawiki.product_metrics.contributors.experiments';

const CLOSE_BUTTON_SELECTOR_VISUAL_MODE = '.ve-ui-toolbar-group-back > .oo-ui-toolGroup-tools > * > a';
const CLOSE_BUTTON_SELECTOR_SOURCE_MODE = '.overlay-header.header.initial-header > ul > li > button.cancel';

const CTR_TARGET_ELEMENTS = [
	{ selector: '.actions > a.signup', friendlyName: 'Sign up' },
	{ selector: '.actions > a.login', friendlyName: 'Log in' },
	{ selector: '.actions > a.anonymous', friendlyName: 'Anon editing' },
	{ selector: '.anon-msg > * > a, .anon-msg > a', friendlyName: 'Temp account info' },
	{
		selector: CLOSE_BUTTON_SELECTOR_VISUAL_MODE +
			', ' + CLOSE_BUTTON_SELECTOR_SOURCE_MODE,
		friendlyName: 'Close button'
	}
];
function setupLoggedOutWarningInstrumentation() {
	// Used to avoid logging multiple exposure events when the editor is re-opened
	let exposureLogged = false;
	// Used to avoid re-setting CTRs after a editing mode switch, which does not
	// show the warning
	let lastEditorUsed = null;
	const setupCTRs = ( experiment ) => {
		const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.testKitchen' );
		CTR_TARGET_ELEMENTS.forEach( ( targetElement ) => {
			const { selector, friendlyName, element } = targetElement;
			if ( exposureLogged ) {
				ClickThroughRateInstrument.stop( element );
			}
			targetElement.element = ClickThroughRateInstrument.start(
				selector, friendlyName, experiment
			);
		} );
	};
	const submitExposureInteraction = ( exp ) => {
		if ( !exposureLogged ) {
			exp.sendExposure();
			exposureLogged = true;
		}
	};
	const submitEditInteraction = ( exp, newRevId ) => {
		exp.send( 'edit_saved', {
			page: {
				namespace_id: mw.config.get( 'wgNamespaceNumber' ),
				revision_id: newRevId
			}
		} );
	};
	// Experiment is only for MobileFrontend enabled sites
	if ( mw.config.get( 'wgMFMode' ) === null ) {
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

	experimentPromise.then( ( exp ) => {
		const submitSourceEditorEditInteraction = ( newRevId ) => submitEditInteraction( exp, newRevId );
		const setupVisualEditorInstrumentation = ( target ) => {
			if ( target.constructor.static.trackingName !== 'mobile' ) {
				return;
			}
			// Log visual mode edit saves regardless of the user being anon or not
			target.once( 'save', ( data ) => submitEditInteraction( exp, data.newrevid ) );
			target.overlay.once( 'editor-loaded', () => {
				// Experiment exposure and CTRs are only for anon users who should see
				// the anon warning
				if ( !mw.user.isAnon() ) {
					return;
				}
				if ( !document.querySelector( '.anonwarning-soft,.anonwarning' ) ) {
					mw.errorLogger.logError(
						new Error( 'Unexpected missing anon warning on visualeditor visual mode load' )
					);
					return;
				}
				setupCTRs( exp );
				submitExposureInteraction( exp );
			} );
		};
		// Add instrumentation only to users in experiment
		if ( !( exp && exp.isAssignedGroup( 'control', 'treatment' ) ) ) {
			return;
		}
		mw.hook( 'mobileFrontend.editorClosed' ).add( ( isSwitching ) => {
			// Unsubscribe prior registered hook callbacks so they don't fire twice
			mw.hook( 'mobileFrontend.sourceEditor.saveComplete' ).remove( submitSourceEditorEditInteraction );
			mw.hook( 've.newTarget' ).remove( setupVisualEditorInstrumentation );
			if ( isSwitching ) {
				return;
			}
			// Reset editor used state, we want to setup the CTRs if the user re-opens the editor
			lastEditorUsed = null;
		} );
		mw.hook( 'mobileFrontend.editorOpened' ).add( ( editor ) => {
			// If this is a editor mode switch the warning is no longer shown, skip
			if ( lastEditorUsed && lastEditorUsed !== editor ) {
				return;
			}
			if ( editor === 'visualeditor' ) {
				mw.hook( 've.newTarget' ).add( setupVisualEditorInstrumentation );
			}
			if ( editor === 'wikitext' ) {
				// Log visual mode edit saves regardless of the user being anon or not
				mw.hook( 'mobileFrontend.sourceEditor.saveComplete' )
					.add( submitSourceEditorEditInteraction );
				// Experiment exposure and CTRs are only for anon users who should see
				// the anon warning
				if ( !mw.user.isAnon() ) {
					return;
				}
				if ( !document.querySelector( '.anonwarning-soft,.anonwarning' ) ) {
					mw.errorLogger.logError(
						new Error( 'Unexpected missing anon warning on visualeditor source mode load' )
					);
					return;
				}
				setupCTRs( exp );
				submitExposureInteraction( exp );
			}
			lastEditorUsed = editor;
		} );

	} );
}

module.exports = exports = setupLoggedOutWarningInstrumentation;
