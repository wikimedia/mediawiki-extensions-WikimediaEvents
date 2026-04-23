/**
 * A synthetic experiment to test the new external path that Test Kitchen will be sending events
 * originating from instruments and logged-in experiments to.
 */

const EXPERIMENT_ID = 'synth-test-new-external-path';
const INSTRUMENT_ID = 'synth-test-external-path';

mw.loader.using( 'ext.testKitchen' ).then( () => {
	const experiment = mw.testKitchen.compat.getExperiment( EXPERIMENT_ID );
	const instrument = mw.testKitchen.getInstrument( INSTRUMENT_ID );
	const timezoneOffset = new Date().getTimezoneOffset();

	// T417143 Send events if the user is enrolled in the experiment.
	// One event instrument.submitInteraction() is sent to the new external path via normal background queue.
	// The other event instrument.sendImmediately() is sent to the new external path via navigator.sendBeacon().
	// Both events are triggered by page load, page unload (pagehide), and page visibility change.
	if ( experiment.isAssignedGroup( 'control', 'treatment' ) ) {
		// Set action_context for interactionData
		const actionContextTK = {
			method: 'send',
			tz_offset: timezoneOffset
		};

		// Send events on page load.
		const sendPageLoadInteraction = () => {
			instrument.submitInteraction( 'page_load', {
				action_context: JSON.stringify( actionContextTK )
			} );
			instrument.sendImmediately( 'page_load', getImmediateInteractionData() );
		};
		if ( document.readyState === 'complete' ) {
			sendPageLoadInteraction();
		} else {
			window.addEventListener( 'load', sendPageLoadInteraction );
		}

		// Send events when page unloaded via pagehide.
		window.addEventListener( 'pagehide', () => {
			instrument.submitInteraction( 'pagehide', {
				action_context: JSON.stringify( actionContextTK )
			} );
			instrument.sendImmediately( 'pagehide', getImmediateInteractionData() );
		} );

		// Send events on page visibility change.
		let count = 0;
		window.addEventListener( 'visibilitychange', () => {
			if ( document.hidden && count < 3 ) {
				count += 1;
				// Send the number of times visibility was changed to "hidden" as action_context
				// for both event senders.

				// For default event sender
				const actionContext = actionContextTK;
				actionContext.count = String( count );
				const interactionData = {
					action_context: JSON.stringify( actionContext )
				};
				instrument.submitInteraction( 'visibilitychange', interactionData );

				// For sendImmediately event sender
				const countContext = {
					action_context: JSON.stringify( { count: String( count ) } )
				};
				const immediateInteractionData = getImmediateInteractionData( countContext );
				instrument.sendImmediately( 'visibilitychange', immediateInteractionData );
			}
		} );
	}
} );

/**
 * Adds sendImmediately-specific action context fields.
 *
 * @private
 * @param {Object} [interactionData]
 * @return {Object}
 */
function getImmediateInteractionData( interactionData = {} ) {
	const timezoneOffset = new Date().getTimezoneOffset();

	let actionContext = {};

	if ( interactionData.action_context ) {
		actionContext = JSON.parse( interactionData.action_context );
	}

	const mergedActionContext = Object.assign(
		{},
		actionContext,
		{
			method: 'sendImmediately',
			tz_offset: timezoneOffset
		}
	);

	return Object.assign(
		{},
		interactionData,
		{
			action_context: JSON.stringify( mergedActionContext )
		}
	);
}
