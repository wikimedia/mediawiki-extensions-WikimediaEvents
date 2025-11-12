/**
 * Frontend instrumentation for hCaptcha
 */
function setupInstrumentation() {
	// This dictionary maps the interfaceName values that are for editing interfaces
	// to the string that should be used as editor_interface in the 'editAttemptStep'
	// mw.track call. visualEditorFeatureUse handles editor_interface for us.
	const editingInterfaces = { edit: 'wikitext', visualeditor: 'visualeditor' };

	// Emit an event when various callbacks fire from hcaptcha.render()
	mw.trackSubscribe( 'confirmEdit.hCaptchaRenderCallback', ( _, event, interfaceName, error ) => {
		// ./specialCreateAccount/instrumentation.js already handles this
		// when the interface is account creation, so only need to handle editing interfaces here
		if ( Object.keys( editingInterfaces ).includes( interfaceName ) ) {
			if ( event === 'open' ) {
				mw.track( 'editAttemptStep', {
					action: 'saveFailure',
					message: 'hcaptcha',
					type: 'captchaExtension',
					editor_interface: editingInterfaces[ interfaceName ]
				} );
			}

			mw.track( 'visualEditorFeatureUse', {
				feature: 'hcaptcha',
				// Possible values:
				// - open
				// - close
				// - chalexpired
				// - expired
				// - error
				action: event
			} );

			if ( error ) {
				// If there was an error, record it as an additional event.
				// Errors are tracked as additional events because visualEditorFeatureUse
				// does not have a specific field for including error information, so
				// it can't be included as part of the first visualEditorFeatureUse event.
				mw.track( 'visualEditorFeatureUse', {
					feature: 'hcaptcha_error',
					// "error" is an hCaptcha error code (for example,
					// "rate-limited"). The full list of values can be found at
					// https://docs.hcaptcha.com/configuration/#error-codes
					action: error
				} );
			}
		}
	} );

	mw.trackSubscribe( 'stats.mediawiki_confirmedit_hcaptcha_execute_total', ( _, count, data ) => {
		if ( Object.keys( editingInterfaces ).includes( data.interfaceName ) ) {
			mw.track( 'visualEditorFeatureUse', {
				feature: 'hcaptcha',
				action: 'execute',
				editor_interface: editingInterfaces[ data.interfaceName ]
			} );
		}
	} );

	// T410354 A/B test of hCaptcha: log an event when the "loaded" action for editAttemptStep
	// fires, to record which experiment group a given editing session belongs to
	mw.trackSubscribe( 'editAttemptStep', ( _, data ) => {
		if ( ![ 'zhwiki', 'jawiki' ].includes( mw.config.get( 'wgDBname' ) ) ) {
			return;
		}
		// We only want this to fire on the action=loaded event from editAttemptStep
		if ( !data || data.action !== 'loaded' ) {
			return;
		}
		mw.loader.using( [ 'ext.xLab' ] ).then( () => {
			const experiment = mw.xLab.getExperiment( 'fy25-26-we-4-2-hcaptcha-editing' );
			mw.track( 'visualEditorFeatureUse', {
				feature: 'T410354_hcaptcha_edit_ab_test',
				action: experiment.isAssignedGroup( 'control', 'control-2' ) ? 'FancyCaptcha' : 'hCaptcha'
			} );
		} );
	} );
}

module.exports = setupInstrumentation;
