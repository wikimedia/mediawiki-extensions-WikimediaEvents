/**
 * Adds the given context to the `action_context` field of all events sent by the generic
 * instrumentation.
 *
 * @example
 *  const { anyPageVisit, withContext } = require( 'ext.wikimediaEvents.testKitchen' );
 *
 *  // This instrumentation will send an `action=page_visit` event with `action_context.user_role`
 *  // set.
 *  const anyPageVisitWithUserRole = withContext( anyPageVisit(), {
 *      user_role: getUserRole()
 *  } );
 *
 *  const e = await mw.testKitchen.getExperiment( 'my-awesome-experiment' );
 *
 *  e.use( anyPageVisitWithUserRole() );
 *
 * @param {mw.testKitchen.GenericInstrumentation} instrumentation
 * @param {Object} context
 * @return {mw.testKitchen.GenericInstrumentation}
 */
function withContext( instrumentation, context ) {
	return ( eventSender ) => {
		/** @type {mw.testKitchen.EventSenderInterface} */
		const wrappedEventSender = {
			send( action, interactionData, contextualAttributes ) {
				interactionData = interactionData || {};
				let actionContext = {};

				if ( interactionData.action_context ) {
					actionContext = JSON.parse( interactionData.action_context );
				}

				actionContext = Object.assign(
					actionContext,
					context
				);

				interactionData.action_context = JSON.stringify( actionContext );

				eventSender.send( action, interactionData, contextualAttributes );
			}
		};

		instrumentation( wrappedEventSender );
	};
}

module.exports = {
	withContext
};
