/*!
 * Product-health instrumentation for the diff page, for the diff-health-metrics Test Kitchen
 * instrument (T434795).
 *
 * Records an impression and a click for each interactive affordance on a diff, on desktop and
 * mobile, so that we can measure what editors actually do when reviewing an edit before the
 * diff experience is redesigned (T436250, T436253, T436254).
 *
 * Clicks come from the shared ClickThroughRateInstrument, which is bound to every place an
 * affordance is rendered so that no click is missed. Impressions come from impressions.js
 * instead, at most one per affordance; see the comment there for why the shared component's
 * own impression is not usable on a diff page.
 *
 * Which wikis this collects on, and at what rate, is the instrument's sample_rate map in the
 * Test Kitchen UI, not a config variable here.
 *
 * Safely no-ops when Test Kitchen is unavailable or the reader is out of sample.
 */

const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.testKitchen' );
const context = require( './context.js' );
const diffModeSwitcher = require( './diffModeSwitcher.js' );
const elements = require( './elements.js' );
const impressions = require( './impressions.js' );

const INSTRUMENT_NAME = 'diff-health-metrics';

// ext.testKitchen is only added to the page when experiments are enabled. wgDiffNewId is set
// by DifferenceEngine::showDiffPage(), so requiring it also filters out any page that merely
// carries a diff query parameter without rendering a diff.
if ( !mw.testKitchen || mw.config.get( 'wgDiffNewId' ) === null ) {
	return;
}

const instrument = mw.testKitchen.getInstrument( INSTRUMENT_NAME );

if ( !instrument.isInSample() ) {
	return;
}

/**
 * Wrap the instrument so that every event carries the diff context, which
 * ClickThroughRateInstrument knows nothing about, and, where an affordance is rendered in
 * more than one place, which of those places the event came from.
 *
 * @param {Object} [options]
 * @param {string} [options.friendlyName] The spec's name for the affordance. Set here as
 *  well as by ClickThroughRateInstrument, so that impressions.js does not have to know it
 * @param {string} [options.elementId]
 * @param {string} [options.funnelEntryToken] Overrides the per-selector token
 *  ClickThroughRateInstrument generates, so that one affordance's impression and clicks
 *  share a token however many places it is rendered in
 * @return {Object} An event sender
 */
function newSender( { friendlyName, elementId, funnelEntryToken } = {} ) {
	return {
		send: function ( action, interactionData, contextualAttributes ) {
			const data = Object.assign( {}, interactionData, {
				action_context: context.newActionContext()
			} );

			if ( friendlyName ) {
				data.element_friendly_name = friendlyName;
			}

			if ( elementId ) {
				data.element_id = elementId;
			}

			if ( funnelEntryToken ) {
				data.funnel_entry_token = funnelEntryToken;
			}

			instrument.send( action, data, contextualAttributes );
		}
	};
}

/**
 * @param {Object} sender
 * @return {Object} The same sender with its impressions dropped, for use with
 *  ClickThroughRateInstrument
 */
function clicksOnly( sender ) {
	return {
		send: function ( action, interactionData, contextualAttributes ) {
			if ( action === 'impression' ) {
				return;
			}

			sender.send( action, interactionData, contextualAttributes );
		}
	};
}

/** @type {Array<Object>} ClickThroughRateInstrument state entries */
let tracked = [];

function stopTracking() {
	tracked.forEach( ( entry ) => ClickThroughRateInstrument.stop( entry ) );
	tracked = [];
	impressions.stop();
}

function startTracking() {
	elements.forEach( ( { friendlyName, targets } ) => {
		// One token per affordance rather than per selector, so that an impression on the
		// copy the reader could see links up with a click on any copy.
		const funnelEntryToken = mw.user.generateRandomSessionId();

		Object.keys( targets ).forEach( ( elementId ) => {
			const selector = targets[ elementId ];

			// Most of these selectors are legitimately absent on any given diff -- rollback
			// needs the right, tags need tags, the mobile copies are only shown by
			// MinervaNeue -- and ClickThroughRateInstrument warns for a selector that
			// matches nothing, so check before asking it to track one.
			if ( !document.querySelector( selector ) ) {
				return;
			}

			const sender = newSender( {
				friendlyName: friendlyName,
				elementId: elementId,
				funnelEntryToken: funnelEntryToken
			} );

			const entry = ClickThroughRateInstrument.start(
				selector,
				friendlyName,
				clicksOnly( sender )
			);

			if ( entry ) {
				tracked.push( entry );
				impressions.observe( entry.element, friendlyName, sender );
			}
		} );
	} );
}

let trackedRevId = null;

// wikipage.diff is fired once table.diff[data-mw-interface] is in the document, and is
// memorised, so this handler performs the initial registration whether or not the diff has
// already rendered by the time this module runs.
//
// It fires again when RevisionSlider swaps out #mw-content-text after a revision pointer is
// dragged, which detaches every element tracked above. That is a genuinely new diff -- the
// revision ids change with it -- so re-registering, and with it a fresh set of impressions,
// is the intended behaviour. Comparing the revision id keeps a redundant fire for the diff we
// are already tracking from double-counting.
mw.hook( 'wikipage.diff' ).add( () => {
	const revId = mw.config.get( 'wgDiffNewId' );

	if ( tracked.length && revId === trackedRevId ) {
		return;
	}

	trackedRevId = revId;

	stopTracking();
	startTracking();
	diffModeSwitcher.start( newSender() );
} );
