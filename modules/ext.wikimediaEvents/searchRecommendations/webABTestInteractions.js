/**
 * Interaction event logging for Web A/B experiments
 *
 * Log actions via the dedicated stream for A/B tests and collect relevant data,
 * such as search activity ID
 *
 * https://phabricator.wikimedia.org/T383611
 */

/**
 * Log an experiment interaction via the correct stream, passing along relevant interaction data,
 * such as action_source
 *
 * @param {string} action - the action the user took (click, show, type, init)
 * @param {Object} interactionData - additional parameters for data analysis
 * @param {string} [interactionData.action_source] - set for click and type events
 * @param {string} [interactionData.action_subtype] - set for show events
 * @param {string} [interactionData.funnel_name] the A/B test group
 */
function logInteraction( action, interactionData ) {
	mw.eventLog.submitInteraction(
		'product_metrics.web_base.search_ab_test_clicks',
		'/analytics/product_metrics/web/base/1.3.0',
		action,
		interactionData
	);
}

module.exports = {
	logInteraction
};
