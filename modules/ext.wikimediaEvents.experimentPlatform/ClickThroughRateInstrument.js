const STREAM_NAME = 'product_metrics.web_base';
const SCHEMA_ID = '/analytics/product_metrics/web/base/1.3.1';

// State
// =====

/**
 * @typedef {Object} StateEntry
 * @property {string} selector
 * @property {string} friendlyName
 * @property {Element} element
 * @property {number} elementClickCount
 * @property {string} funnelEntryToken
 */

/** @type {Map<HTMLElement,StateEntry>} */
const state = new WeakMap();

// Event Listeners
// ===============

// eslint-disable-next-line compat/compat
const intersectionObserver = new IntersectionObserver(
	( entries, observer ) => {
		entries.forEach( ( { target } ) => {
			if ( state.has( target ) ) {
				const {
					funnelEntryToken,
					elementFriendlyName
				} = state.get( target );

				mw.eventLog.submitInteraction(
					STREAM_NAME,
					SCHEMA_ID,
					'impression',
					{
						action_source: 'ClickThroughRateInstrument',
						funnel_entry_token: funnelEntryToken,
						funnel_event_sequence_position: 1,
						element_friendly_name: elementFriendlyName
					}
				);
			}

			observer.unobserve( target );
		} );
	},
	{
		threshold: 1
	}
);

document.addEventListener( 'click', ( { target } ) => {
	if ( state.has( target ) ) {
		const entry = state.get( target );

		mw.eventLog.submitInteraction(
			STREAM_NAME,
			SCHEMA_ID,
			'click',
			{
				action_source: 'ClickThroughRateInstrument',
				funnel_entry_token: entry.funnelEntryToken,
				funnel_event_sequence_position: 2 + entry.elementClickCount,
				element_friendly_name: entry.friendlyName
			}
		);

		++entry.elementClickCount;
	}
} );

// API
// ===

/**
 * An instrument that tracks impressions and clicks of a DOM element.
 *
 * When an element
 *
 * ## Usage
 *
 * ```
 * const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.metricsPlatform' );
 *
 * const result = ClickThroughRateInstrument.start(
 *     '[data-pinnable-element-id="vector-main-menu"] .vector-pinnable-header-unpin-button',
 *     'pinnable-header.vector-main-menu.unpin'
 * );
 *
 * // A few moments later…
 *
 * ClickThroughRateInstrument.stop( result );
 * ```
 *
 * ## Events
 *
 * ### Impression
 *
 * The `action=impression` event is submitted soon after the element is fully visible in the
 * viewport. The event is submitted once. The event has the following fields:
 *
 * | Field                          | Type      | Value(s)                       |
 * | ------------------------------ | --------- | ------------------------------ |
 * | action                         | string    | `"impression"`                 |
 * | action_source                  | string    | `"ClickThroughRateInstrument"` |
 * | funnel_entry_token             | Token     |                                |
 * | funnel_event_sequence_position | usmallint | `1`                            |
 * | element_friendly_name          | string    |                                |
 *
 * ### Click
 *
 * The `action=click` event is submitted when the user clicks the element. The event can be
 * submitted more than once. The event has the following fields:
 *
 * | Field                          | Type      | Value(s)                       |
 * | ------------------------------ | --------- | ------------------------------ |
 * | action                         | string    | `"click"`                      |
 * | action_source                  | string    | `"ClickThroughRateInstrument"` |
 * | funnel_entry_token             | Token     |                                |
 * | funnel_event_sequence_position | usmallint | `2`, `3`, `4`, etc.            |
 * | element_friendly_name          | string    |                                |
 *
 * ## Notes
 *
 * 1. The `action=click` event is submitted when the user clicks the element. The instrument
 *    detects this by listening to the [Element: click event][0], which occurs when:
 *
 *    > * a pointing-device button (such as a mouse's primary button) is both pressed and released
 *    >   while the pointer is located inside the element.
 *    > * a touch gesture is performed on the element
 *    > * the `Space` key or `Enter` key is pressed while the element is focused
 *
 * [0]: https://developer.mozilla.org/en-US/docs/Web/API/Element/click_event
 *
 * @class ClickThroughRateInstrument
 * @singleton
 * @unstable
 */
const ClickThroughRateInstrument = {

	/**
	 * @param {string} selector
	 * @param {string} friendlyName
	 * @return {StateEntry|null}
	 */
	start( selector, friendlyName ) {
		const e = document.querySelector( selector );

		if ( !e ) {
			mw.log.warn( 'Experiment Platform: selector does not exist - ' + e );
			return null;
		}

		let result;

		if ( state.has( e ) ) {
			result = state.get( e );
		} else {
			result = {
				selector,
				friendlyName,
				element: e,
				elementClickCount: 0,
				funnelEntryToken: mw.user.generateRandomSessionId()
			};

			state.set( e, result );

			intersectionObserver.observe( e );
		}

		// Return a copy of the internal state so that it can't be modified by third-parties but
		// can still be used to stop the instrument tracking the element (see
		// ClickThroughRateInstrument#stop() below).
		return Object.assign( {}, result );
	},

	stop( { element } ) {
		intersectionObserver.unobserve( element );
		state.delete( element );
	}
};

module.exports = ClickThroughRateInstrument;
