const instrument = require( './useInstrument.js' );

/**
 * Build the action_context for the impression event (T442454).
 * Shorten the returnto value, so that the result stays valid JSON
 * and fits in the action_context field.
 *
 * @param {boolean} isBlocked
 * @param {string} returnTo
 * @return {string}
 */
function getImpressionContext( isBlocked, returnTo ) {
	// We can cut a character in half (e.g. %20 at the end can become %2), but that's only for
	// analytics and we're okay with truncated URLs anyway
	returnTo = encodeURI( returnTo ).slice( 0, 200 );
	return JSON.stringify( {
		is_blocked: isBlocked,
		return_to: returnTo
	} );
}

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
		captchaWord: 'captcha',
		email: 'email',
		realname: 'real_name',
		reason: 'reason'
	};

	if ( mw.config.get( 'wgWikimediaEventsCreateAccountIsNewRequest' ) ) {
		const urlParams = new URLSearchParams( window.location.search );
		submitInteraction( 'impression', {
			source: 'page',
			context: getImpressionContext(
				!!mw.config.get( 'wgWikimediaEventsCreateAccountIsBlocked' ),
				urlParams.get( 'returnto' ) || ''
			)
		} );
	}

	const errorPageMessageKey = mw.config.get( 'wgErrorPageMessageKey' );
	if ( errorPageMessageKey ) {
		submitInteraction( 'form_error', {
			source: 'form',
			context: errorPageMessageKey
		} );
	}

	// Record clicks on the links of the page that is shown instead of the form
	// when the user cannot create an account (T443743).
	const blockPageContext = JSON.stringify(
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '.mw-createaccount-ipblocked' ).length ?
			{ page: 'ipblocked' } :
			{ page: 'error', message_key: errorPageMessageKey }
	);
	const blockPageLinks = {
		'#mw-createaccount-permerror-login': 'permerror-login',
		'#mw-createaccount-permerror-return': 'permerror-continue',
		'#mw-createaccount-ipblocked-details a': 'block-more'
	};
	for ( const selector in blockPageLinks ) {
		$( selector ).on( 'mousedown', () => {
			submitInteraction( 'click', {
				source: 'page',
				subType: blockPageLinks[ selector ],
				context: blockPageContext
			} );
		} );
	}

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

			// Record the CAPTCHA type that the user is interacting with
			submitInteraction( 'captcha_class_clientside', {
				source: 'form',
				context: mw.config.get( 'wgWikimediaEventsCaptchaClassType' )
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
			submitInteraction( 'click', {
				source: 'form',
				subType: 'presubmit',
				context: $form.find( 'input[name=wpName]' ).val().trim()
			} );
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

	// Emit interaction events for performance measurements, e.g. hCaptcha load and execute timing.
	mw.trackSubscribe( 'specialCreateAccount.performanceTiming', ( _, measurementName, durationSeconds ) => {
		submitInteraction( measurementName, {
			source: 'form',
			context: durationSeconds
		} );
	} );

	// Emit an event when various callbacks fire from hcaptcha.render()
	mw.trackSubscribe( 'confirmEdit.hCaptchaRenderCallback', ( _, event, interfaceName, error ) => {
		// Note events coming from the editor are handled by hCaptcha.js
		if ( interfaceName !== 'createaccount' ) {
			return;
		}
		submitInteraction( 'hcaptcha_render', {
			// Possible values:
			// - open
			// - close
			// - chalexpired
			// - expired
			// - error
			context: event
		} );

		if ( error ) {
			// If there was an error, record it as an additional interaction event.
			// Errors are tracked as additional events because the events for
			// account creation don't have a specific field for including error
			// information, so it can't be included as part of the event for the
			// hcaptcha_render action itself.
			submitInteraction( 'hcaptcha_error', {
				// "error" is an hCaptcha error code (for example,
				// "rate-limited"). The full list of values can be found at
				// https://docs.hcaptcha.com/configuration/#error-codes
				context: error
			} );
		}
	} );
}

module.exports = setupInstrumentation;
module.exports.getImpressionContext = getImpressionContext;
