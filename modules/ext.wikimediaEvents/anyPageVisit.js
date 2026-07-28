/**
 * Reusable instrumentation for producing page_visit events
 * from experiments conducted with Test Kitchen.
 *
 * Used for calculation of page visit-based metrics such as:
 * - Second day web reader retention
 * - Second week web reader retention
 * - 21-day web reader retention
 * in experiments analyzed in Test Kitchen.
 *
 * Usage:
 * ```
 * // Assuming `experiment` is an instance of mw.testKitchen.Experiment
 * const anyPageVisit = require( './anyPageVisit.js' );
 * experiment.use( anyPageVisit );
 * ```
 *
 * If used as-is, this is a low risk data collection. Risk level
 * may increase with inclusion of certain contextual attributes
 * at experiment/instrument level.
 *
 * See also:
 * - ./accountCreation/accountCreated.js
 * - ./editSaved.js
 */

const PAGE_VISIT_ACTION = 'page_visit';
const PAGE_VISIT_ATTRIBUTES = [
	'page_namespace_id' // Required for metrics concerned with *article* visits specifically
];

/**
 * @param {mw.testKitchen.EventSenderInterface} eventSender
 */
function anyPageVisit( eventSender ) {
	eventSender.send( PAGE_VISIT_ACTION, {}, PAGE_VISIT_ATTRIBUTES );
}

module.exports = anyPageVisit;
