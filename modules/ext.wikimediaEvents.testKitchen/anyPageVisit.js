/**
 * Reusable instrumentation for producing page_visit events
 * from experiments conducted with Test Kitchen.
 *
 * Used for calculation of page-visit-based metrics such as:
 * - Second day web reader retention
 * - Second week web reader retention
 * - 21-day web reader retention
 * - Daily Web Visit Rate
 * in experiments analyzed in Test Kitchen.
 *
 * Optionally records the visit's referrer class in `action_source` so that
 * experiments can compute metrics such as the ratio of internally- to
 * externally-referred (or direct) page visits. This is opt-in and disabled
 * by default; see the `recordReferrerClass` option below.
 *
 * Usage:
 * ```
 * // Assuming `experiment` is an instance of mw.testKitchen.Experiment
 * const { anyPageVisit } = require( 'ext.wikimediaEvents.testKitchen' );
 * experiment.use( anyPageVisit() );
 *
 * // To also record the referrer class in action_source:
 * experiment.use( anyPageVisit({ recordReferrerClass: true }));
 * ```
 *
 * If used as-is, this is a low-risk data collection. The risk level
 * may increase with the inclusion of certain contextual attributes
 * at experiment/instrument level.
 *
 * See also:
 * - ../ext.wikimediaEvents/accountCreation/accountCreated.js
 * - ../ext.wikimediaEvents/editSaved.js
 */

const PAGE_VISIT_ACTION = 'page_visit';
const PAGE_VISIT_ATTRIBUTES = [
	'page_namespace_id' // Required for metrics concerned with *article* visits specifically
];

/**
 * Referrer classes recorded in action_source. Kept deliberately coarse:
 * distinguishing the *type* of external referrer (e.g., search vs. social) is
 * not valuable for on-site experiments.
 */
const REFERRER_CLASS_NONE = 'no_referrer';
const REFERRER_CLASS_INTERNAL = 'internal_referrer';
const REFERRER_CLASS_EXTERNAL = 'external_referrer';

/**
 * Wikimedia canonical project domains. A referrer whose host is (or is a
 * subdomain of) one of these is treated as an internal referrer.
 *
 * This mirrors the `internal` vs. `external` distinction that refinery's
 * RefererClassifier.java makes for webrequest/pageview data, so that
 * client-side referrer classes line up with the ones derived server-side.
 */
const WIKIMEDIA_DOMAINS = [
	'wikipedia.org',
	'wikimedia.org',
	'wiktionary.org',
	'wikibooks.org',
	'wikinews.org',
	'wikiquote.org',
	'wikisource.org',
	'wikiversity.org',
	'wikivoyage.org',
	'wikidata.org',
	'wikifunctions.org',
	'mediawiki.org',
	'wikimediafoundation.org',
	'wikiba.se'
];

/**
 * @param {string} host A URL hostname, e.g. "en.wikipedia.org"
 * @return {boolean} Whether the host is a Wikimedia project domain or a
 *  subdomain of one.
 */
function isWikimediaHost( host ) {
	return WIKIMEDIA_DOMAINS.some(
		( domain ) => host === domain || host.endsWith( '.' + domain )
	);
}

/**
 * Classify a referrer string into one of the REFERRER_CLASS_* values.
 *
 * @param {string} referrer Typically `document.referrer`
 * @return {string} One of the REFERRER_CLASS_* values
 */
function classifyReferrer( referrer ) {
	if ( !referrer ) {
		return REFERRER_CLASS_NONE;
	}
	let host;
	try {
		host = new URL( referrer ).hostname;
	} catch ( e ) {
		// A non-empty referrer that does not parse as a URL is unusual (browsers
		// set document.referrer to a full URL when present). Treat it as external
		// rather than dropping the signal: there was a referrer, and it is not a
		// recognized Wikimedia host.
		return REFERRER_CLASS_EXTERNAL;
	}
	return isWikimediaHost( host ) ?
		REFERRER_CLASS_INTERNAL :
		REFERRER_CLASS_EXTERNAL;
}

/**
 * Build a page_visit event sender for use with `experiment.use()`.
 *
 * @param {Object} [config]
 * @param {boolean} [config.recordReferrerClass=false] Whether to record the
 *  referrer class (see the REFERRER_CLASS_* values) in the event's action_source.
 * @return {function(mw.testKitchen.EventSenderInterface): void}
 */
function anyPageVisit( { recordReferrerClass = false } = {} ) {
	const interactionData = {};
	if ( recordReferrerClass ) {
		interactionData.action_source = classifyReferrer( document.referrer );
	}
	return ( eventSender ) => {
		eventSender.send( PAGE_VISIT_ACTION, interactionData, PAGE_VISIT_ATTRIBUTES );
	};
}

// Exposed for unit testing.
if ( window.QUnit ) {
	anyPageVisit.classifyReferrer = classifyReferrer;
	anyPageVisit.REFERRER_CLASS_NONE = REFERRER_CLASS_NONE;
	anyPageVisit.REFERRER_CLASS_INTERNAL = REFERRER_CLASS_INTERNAL;
	anyPageVisit.REFERRER_CLASS_EXTERNAL = REFERRER_CLASS_EXTERNAL;
}

module.exports = anyPageVisit;
