/*!
 * Builds the action_context payload carried by every diff-health-metrics event (T434795).
 */

const diffMode = require( './diffMode.js' );
const feedSources = require( './feedSources.json' );

/**
 * Query parameter that the Review Changes cards on Special:PersonalDashboard will set on
 * their diff links, to record that the reader arrived from the dashboard and from which
 * feed. Nothing sets it yet; see T421397. Reading it here means the data starts arriving as
 * soon as that task ships, with no further change to this instrument.
 */
const ORIGIN_PARAM = 'origin';

/**
 * Start of the value of ORIGIN_PARAM. The Review Changes cards make the value from this
 * prefix and the name of the feed source that the card came from. The PersonalDashboard
 * extension holds the same constant, in ListCard.vue.
 */
const ORIGIN_PREFIX = 'personaldashboard-';

/** @type {string[]|undefined} */
let originValues;

/** @type {string|null|undefined} */
let origin;

/**
 * The recognised values of ORIGIN_PARAM, one for each feed source that Personal Dashboard
 * registers. The names come from the server, from the PersonalDashboardFeedSources
 * attribute, so a new feed source needs no change here. The list is empty if Personal
 * Dashboard is not installed, and then no origin is recognised.
 *
 * @return {string[]}
 */
function getOriginValues() {
	if ( originValues === undefined ) {
		originValues = feedSources.sources.map( ( source ) => ORIGIN_PREFIX + source );
	}

	return originValues;
}

/**
 * @return {string|null} The validated origin, or null if absent or unrecognised
 */
function getOrigin() {
	const value = mw.util.getParamValue( ORIGIN_PARAM );

	// A query parameter is reader-controlled input, so drop all other values.
	return getOriginValues().includes( value ) ? value : null;
}

/**
 * @return {string} JSON, for the event's action_context field. Well within the field's
 *  320-character limit.
 */
function newActionContext() {
	if ( origin === undefined ) {
		// How the reader arrived is fixed for the lifetime of the page.
		origin = getOrigin();
	}

	const context = {
		diff_mode: diffMode.get(),
		// The page_revision_id contextual attribute is unusable here: Article::showDiffPage()
		// never calls OutputPage::setRevisionId(), so wgRevisionId is 0 on a diff. wgDiffNewId
		// is false for a "fake" diff, e.g. the diff of a page creation (T338388).
		rev_id: mw.config.get( 'wgDiffNewId' ) || null
	};

	if ( origin !== null ) {
		context.origin = origin;
	}

	return JSON.stringify( context );
}

module.exports = {
	newActionContext: newActionContext
};

// Exposed for unit testing.
if ( window.QUnit ) {
	module.exports.ORIGIN_PARAM = ORIGIN_PARAM;
	module.exports.ORIGIN_PREFIX = ORIGIN_PREFIX;
	module.exports.getOriginValues = getOriginValues;
	module.exports.getOrigin = getOrigin;
	module.exports.reset = function () {
		origin = undefined;
		originValues = undefined;
	};
}
