/**
 * Frontend instrumentation for hCaptcha
 */
function setupInstrumentation() {
	// Emit an event when various callbacks fire from hcaptcha.render()
	mw.trackSubscribe( 'confirmEdit.hCaptchaRenderCallback', ( _, event, interfaceName ) => {
		// ./specialCreateAccount/instrumentation.js already handles this
		// when the interface is account creation, so only need to handle editing interfaces here
		const editingInterfaces = [ 'edit', 'visualeditor' ];

		if ( editingInterfaces.includes( interfaceName ) ) {
			if ( event === 'open' ) {
				mw.track( 'editAttemptStep', {
					action: 'saveFailure',
					message: 'hcaptcha',
					type: 'captchaExtension'
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
}

module.exports = setupInstrumentation;
