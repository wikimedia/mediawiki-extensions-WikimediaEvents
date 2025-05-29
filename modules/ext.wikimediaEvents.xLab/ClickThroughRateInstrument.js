const STREAM_NAME = 'product_metrics.web_base';
const SCHEMA_ID = '/analytics/product_metrics/web/base/1.4.2';

// State
// =====

/**
 * @typedef {Object} StateEntry
 * @property {string} selector
 * @property {string} friendlyName
 * @property {Element} element
 * @property {string} funnelEntryToken
 * @property {Instrument} instrument
 */

/** @type {Map<string,StateEntry>} */
const state = new Map();

/**
 * @param {Object} stateEntry
 * @param {string} action
 */
function submitInteraction( stateEntry, action ) {
	const {
		funnelEntryToken,
		friendlyName,
		instrument
	} = stateEntry;

	instrument.submitInteraction( action, {
		instrument_name: 'ClickThroughRateInstrument',
		funnel_entry_token: funnelEntryToken,
		element_friendly_name: friendlyName
	} );
}

// Event Listeners
// ===============

// eslint-disable-next-line compat/compat
const intersectionObserver = new IntersectionObserver(
	( entries, observer ) => {
		entries.forEach( ( { target } ) => {
			state.forEach( ( stateEntry ) => {
				if ( stateEntry.element === target ) {
					submitInteraction( stateEntry, 'impression' );
				}
			} );

			observer.unobserve( target );
		} );
	},
	{
		threshold: 1
	}
);

document.addEventListener( 'click', ( { target } ) => {

	// O(n * m) where:
	//
	// * n - the number of elements tracked by the instrument
	// * m - is the maximum depth of the DOM inside the elements tracked by the instrument
	//
	// n will be trivially small for the foreseeable future.
	//
	// Generally, m depends on how the instrument is used, which Experiment Platform will need to
	// track.

	state.forEach( ( stateEntry ) => {

		// Note well that e.contains( e ) return true. This handles the simple case where the event
		// target is an element that is being tracked by the instrument.
		if ( stateEntry.element.contains( target ) ) {
			submitInteraction( stateEntry, 'click' );
		}
	} );
} );

// API
// ===

/**
 * An instrument that tracks clickthrough rate of DOM elements. See
 * https://meta.wikimedia.org/wiki/Research_and_Decision_Science/Data_glossary/Clickthrough_Rate for
 * the definition and specification of clickthrough rate.
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
 * // ClickThroughRateInstrument also accepts an optional Instrument parameter:
 *
 * const thisInstrument = instrument || mw.eventLog.newInstrument( STREAM_NAME, SCHEMA_ID );
 *
 * const result = ClickThroughRateInstrument.start(
 *     '[data-pinnable-element-id="vector-main-menu"] .vector-pinnable-header-unpin-button',
 *     'pinnable-header.vector-main-menu.unpin',
 *     thisInstrument
 * );
 *
 * // If you want to stop the instrument for any reason:
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
 * | instrument_name                | string    | `"ClickThroughRateInstrument"` |
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
 * | instrument_name                | string    | `"ClickThroughRateInstrument"` |
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
	 * @param {Instrument} [instrument]
	 * @return {StateEntry|null}
	 */
	start( selector, friendlyName, instrument ) {
		const e = document.querySelector( selector );

		if ( !e ) {
			mw.log.warn( 'ClickThroughRateInstrument: selector does not exist - ' + selector );
			return null;
		}

		const i = instrument || mw.eventLog.newInstrument( STREAM_NAME, SCHEMA_ID );

		let result;

		if ( state.has( selector ) ) {
			result = state.get( selector );
		} else {
			result = {
				selector,
				friendlyName,
				element: e,
				funnelEntryToken: mw.user.generateRandomSessionId(),
				instrument: i
			};

			state.set( selector, result );

			intersectionObserver.observe( e );
		}

		// Return a copy of the internal state so that it can't be modified by third-parties but
		// can still be used to stop the instrument tracking the element (see
		// ClickThroughRateInstrument#stop() below).
		return Object.assign( {}, result );
	},

	/**
	 * @param {StateEntry} stateEntry
	 */
	stop( { element, selector } ) {
		intersectionObserver.unobserve( element );
		state.delete( selector );
	}
};

module.exports = ClickThroughRateInstrument;
