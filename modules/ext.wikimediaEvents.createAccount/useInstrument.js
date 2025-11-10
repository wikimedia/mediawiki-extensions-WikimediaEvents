'use strict';

/**
 * Additional context for an instrumentation event.
 *
 * @typedef {Object} InteractionData
 * @property {string} [subType]
 * @property {string} [source]
 * @property {string} [context]
 * @property {string} [elementId]
 */

/**
 * @callback LogEvent Log an event to the Special:CreateAccount event stream.
 *
 * @param {string} action
 * @param {InteractionData} [data]
 */

/**
 * Lazy singleton instance of the underlying Metrics Platform instrument.
 */
let instrument;

let funnelEntryToken;

/**
 * Helper to create an event logging function configured to log events to the Special:CreateAccount stream.
 * Submitted interaction events will have an associated funnel entry token
 * that persists across the flow.
 *
 * @return {LogEvent}
 */
const useInstrument = () => {
	if ( !instrument ) {
		instrument = mw.eventLog.newInstrument(
			'mediawiki.product_metrics.special_create_account',
			'/analytics/product_metrics/web/base/1.3.0'
		);
	}

	return ( action, data = {} ) => {
		// Generate a new funnel entry token if none was set, or found in mw.storage.session
		if ( !funnelEntryToken ) {
			// Add the user ID to the token, in case the user visits Special:CreateAccount
			// after creating this account.
			const funnelEntryTokenSessionStorageKey = 'SpecialCreateAccountFunnelToken-' + mw.user.getId();
			funnelEntryToken = mw.storage.session.get( funnelEntryTokenSessionStorageKey ) ||
				mw.user.generateRandomSessionId();
			mw.storage.session.set( funnelEntryTokenSessionStorageKey, funnelEntryToken );
		}

		const interactionData = {
			funnel_entry_token: funnelEntryToken
		};

		if ( data.subType ) {
			interactionData.action_subtype = data.subType;
		}

		if ( data.source ) {
			interactionData.action_source = data.source;
		}

		if ( data.elementId ) {
			interactionData.element_id = data.elementId;
		}

		if ( data.context ) {
			interactionData.action_context = String( data.context ).slice( 0, 64 );
		}

		interactionData.funnel_name = 'create_account';

		instrument.submitInteraction( action, interactionData );
	};
};

module.exports = { useInstrument };
