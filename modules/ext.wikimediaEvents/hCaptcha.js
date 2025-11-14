/**
 * Frontend instrumentation for hCaptcha
 */
function setupInstrumentation() {
	// This dictionary maps the interfaceName values that are for editing interfaces
	// to the string that should be used as editor_interface in the 'editAttemptStep'
	// mw.track call. visualEditorFeatureUse handles editor_interface for us.
	const editingInterfaces = { edit: 'wikitext', visualeditor: 'visualeditor' };

	// Emit an event when various callbacks fire from hcaptcha.render()
	mw.trackSubscribe( 'confirmEdit.hCaptchaRenderCallback', ( _, event, interfaceName ) => {
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
}

module.exports = setupInstrumentation;
