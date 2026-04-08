const EXPERIMENT_NAME = 'growthexperiments-editattempt-anonwarning';
const STREAM_NAME = 'mediawiki.product_metrics.contributors.experiments';

const CLOSE_BUTTON_SELECTOR_VISUAL_MODE = '.ve-ui-toolbar-group-back > .oo-ui-toolGroup-tools > * > a';
const CLOSE_BUTTON_SELECTOR_SOURCE_MODE = '.overlay-header.header.initial-header > ul > li > button.cancel';

const CTR_TARGET_ELEMENTS = [
	{ selector: '.actions > a.signup', friendlyName: 'Sign up' },
	{ selector: '.actions > a.login', friendlyName: 'Log in' },
	{ selector: '.actions > a.anonymous', friendlyName: 'Anon editing' },
	{ selector: '.anon-msg > * > a, .anon-msg > a', friendlyName: 'Temp account info' }
];
const CTR_ADDITIONAL_ELEMENTS = {
	editorCloseButton: 'Close button',
	deviceCloseTab: 'Device close tab',
	deviceBackButton: 'Device back button'
};
function setupLoggedOutWarningInstrumentation() {
	// Used to avoid logging multiple exposure events when the editor is re-opened
	let exposureLogged = false;
	// Used to avoid re-setting CTRs after a editing mode switch, which does not
	// show the warning
	let lastEditorUsed = null;
	// Used track whereas a back navigation happened interacting with the editor close button
	// or interacting with an outside from doc element, eg: browser back.
	let isCloseButtonClick = false;
	// Used to skip editor closed logs once user actually starts editing
	let isAnonEditingButtonClick = false;

	const setupCTRs = ( experiment, submitCloseTab ) => {
		isCloseButtonClick = false;
		isAnonEditingButtonClick = false;
		const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.testKitchen' );
		CTR_TARGET_ELEMENTS.forEach( ( targetElement ) => {
			const { selector, friendlyName, element } = targetElement;
			if ( exposureLogged ) {
				ClickThroughRateInstrument.stop( element );
			}
			targetElement.element = ClickThroughRateInstrument.start(
				selector, friendlyName, experiment
			);
			document.querySelector( selector ).addEventListener( 'click', () => {
				window.removeEventListener( 'pagehide', submitCloseTab );
			} );
		} );

		$( CLOSE_BUTTON_SELECTOR_VISUAL_MODE ).on( 'mousedown touchstart', () => {
			isCloseButtonClick = true;
		} );
		$( CLOSE_BUTTON_SELECTOR_SOURCE_MODE ).on( 'mousedown touchstart', () => {
			isCloseButtonClick = true;
		} );

		window.addEventListener( 'pagehide', submitCloseTab );
		// Send an impression for the close button and back button
		experiment.send( 'impression', {
			element_friendly_name: CTR_ADDITIONAL_ELEMENTS.deviceCloseTab
		} );
		experiment.send( 'impression', {
			element_friendly_name: CTR_ADDITIONAL_ELEMENTS.deviceBackButton
		} );
		experiment.send( 'impression', {
			element_friendly_name: CTR_ADDITIONAL_ELEMENTS.editorCloseButton
		} );

		document.querySelector( '.actions > a.anonymous' ).addEventListener( 'click', () => {
			isAnonEditingButtonClick = true;
			window.removeEventListener( 'pagehide', submitCloseTab );
		} );
	};
	const submitExposureInteraction = ( exp ) => {
		if ( !exposureLogged ) {
			exp.sendExposure();
			exposureLogged = true;
		}
	};
	// Experiment is only for MobileFrontend enabled sites
	if ( mw.config.get( 'wgMFMode' ) === null ) {
		return;
	}

	const experimentPromise = mw.loader.using( [
		'ext.testKitchen',
		'ext.wikimediaEvents.testKitchen'
	] ).then( () => {
		const experiment = mw.testKitchen.compat.getExperiment( EXPERIMENT_NAME );
		experiment.setStream( STREAM_NAME );
		return experiment;
	} ).catch( ( error ) => {
		mw.log( 'Error loading ext.testKitchen module:', error );
		return null;
	} );

	experimentPromise.then( ( exp ) => {
		const submitCloseTab = () => {
			exp.send( 'navigation_out', {
				action_data: lastEditorUsed,
				element_friendly_name: CTR_ADDITIONAL_ELEMENTS.deviceCloseTab
			} );
		};
		const setupVisualEditorInstrumentation = ( target ) => {
			if ( target.constructor.static.trackingName !== 'mobile' ) {
				return;
			}
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
				setupCTRs( exp, submitCloseTab );
				submitExposureInteraction( exp );
			} );
		};
		// Add instrumentation only to users in experiment
		if ( !( exp && exp.isAssignedGroup( 'control', 'treatment' ) ) ) {
			return;
		}
		mw.hook( 'mobileFrontend.editorClosed' ).add( ( isSwitching ) => {
			if ( isAnonEditingButtonClick ) {
				return;
			}
			// Unsubscribe prior registered hook callbacks so they don't fire twice
			mw.hook( 've.newTarget' ).remove( setupVisualEditorInstrumentation );
			if ( isSwitching ) {
				exp.send( 'mode_switch', {
					action_data: lastEditorUsed === 'wikitext' ? 'visualeditor' : 'wikitext',
					element_friendly_name: 'Mode switch'
				} );
				return;
			} else {
				const friendlyName = isCloseButtonClick === true ?
					CTR_ADDITIONAL_ELEMENTS.editorCloseButton :
					CTR_ADDITIONAL_ELEMENTS.deviceBackButton;

				exp.send( 'navigation_back', {
					action_data: lastEditorUsed,
					element_friendly_name: friendlyName
				} );
			}
			// Reset editor used state, we want to setup the CTRs if the user re-opens the editor
			lastEditorUsed = null;
			// Stop listening to close tab
			window.removeEventListener( 'pagehide', submitCloseTab );
		} );
		mw.hook( 'mobileFrontend.editorOpened' ).add( ( editor ) => {
			// If this is a editor mode switch the warning is no longer shown, skip
			if ( lastEditorUsed && lastEditorUsed !== editor ) {
				lastEditorUsed = editor;
				return;
			}
			if ( editor === 'visualeditor' ) {
				mw.hook( 've.newTarget' ).add( setupVisualEditorInstrumentation );
			}
			if ( editor === 'wikitext' ) {
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
				setupCTRs( exp, submitCloseTab );
				submitExposureInteraction( exp );
			}
			lastEditorUsed = editor;
		} );

	} );
}

module.exports = exports = setupLoggedOutWarningInstrumentation;
