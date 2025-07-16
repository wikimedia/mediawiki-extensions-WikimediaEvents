const instrument = require( './useInstrument.js' );

/**
 * Frontend instrumentation for the account creation funnel (T394744).
 * This function should be run after the DOM has become interactive.
 */
function setupInstrumentation() {
	const submitInteraction = instrument.useInstrument();
	const contextsByFieldName = {
		wpName: 'user_name',
		wpPassword: 'password',
		retype: 'confirm_password',
		email: 'email',
		realname: 'real_name',
		reason: 'reason'
	};

	const formReady = Date.now();

	let interactionStart = null;

	// eslint-disable-next-line no-jquery/no-global-selector
	const $form = $( '#userlogin2' );
	const $inputs = $form.find( 'input' );

	// Record clicks on the hCaptcha privacy policy link.
	$form.find( 'a[href="https://www.hcaptcha.com/privacy"]' ).on( 'click', () => {
		submitInteraction( 'click', {
			source: 'form',
			context: 'hcaptcha-privacy-policy'
		} );
	} );

	// Record clicks on the hCaptcha terms of use link.
	$form.find( 'a[href="https://www.hcaptcha.com/terms"]' ).on( 'click', () => {
		submitInteraction( 'click', {
			source: 'form',
			context: 'hcaptcha-terms-of-use'
		} );
	} );

	$inputs.on( 'focus', function () {
		if ( !interactionStart ) {
			// Mark the first time the user interacts with the form
			// to compute the overall time they spend on the account creation screen.
			interactionStart = Date.now();

			const query = new URLSearchParams( window.location.search );

			// Record the time that elapsed between the form becoming interactive and the user engaging with it.
			// Use the time at which the instrumentation code was wired up as a proxy for the former,
			// since the intent of this measurement is to estimate how much time users spend parsing the form,
			// therefore it does not need to be very granular.
			const timeToInteractionStart = interactionStart - formReady;
			submitInteraction( 'view', {
				source: 'form',
				context: timeToInteractionStart / 1000
			} );

			// Record what page, if any, referred the user to the account creation form.
			submitInteraction( 'click', {
				source: 'form',
				context: JSON.stringify( {
					return_to: query.get( 'returnto' ) || ''
				} )
			} );
		}

		// Record the time spent on each field, defined as the time between the user selecting and unfocusing the field.
		const $field = $( this );
		const fieldName = $field.attr( 'name' );
		if ( contextsByFieldName[ fieldName ] ) {
			const inputInteractionStart = Date.now();
			$field.one( 'blur', () => {
				const elapsed = Date.now() - inputInteractionStart;
				submitInteraction( 'blur', {
					source: 'form',
					elementId: contextsByFieldName[ fieldName ],
					context: elapsed / 1000
				} );
			} );
		}
	} );

	// Record changes to individual form inputs.
	$inputs.on( 'change', function () {
		const fieldName = $( this ).attr( 'name' );
		if ( contextsByFieldName[ fieldName ] ) {
			submitInteraction( 'type', {
				source: 'form',
				elementId: contextsByFieldName[ fieldName ]
			} );
		}
	} );

	// Record the overall time taken to fill out and submit the form.
	$form.on( 'submit', () => {
		if ( interactionStart ) {
			const elapsed = Date.now() - interactionStart;
			submitInteraction( 'click', {
				source: 'form',
				subType: 'submit',
				context: elapsed / 1000
			} );
		}
	} );

	// Instrument frontend validation errors.
	mw.trackSubscribe( 'specialCreateAccount.validationErrors', ( topic, errorCodes ) => {
		for ( const code of errorCodes ) {
			submitInteraction( 'view', {
				source: 'form',
				subType: 'validation_error',
				context: code.replace( /-/g, '_' )
			} );
		}
	} );
}

module.exports = setupInstrumentation;
