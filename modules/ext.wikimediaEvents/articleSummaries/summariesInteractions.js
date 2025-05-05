/**
 * Interaction event logging for article summaries
 *
 * Log actions via the dedicated stream for summaries and collect relevant data,
 * such as search activity ID
 *
 * https://phabricator.wikimedia.org/T387406
 */

/**
 * Log an experiment interaction via the correct stream, passing along relevant interaction data,
 * such as action_source
 *
 * @param {string} action - the action the user took (click, show, init)
 * @param {Object} interactionData - additional parameters for data analysis
 * @param {string} [interactionData.action_source] - set for click events
 * @param {string} [interactionData.action_subtype] - set for all actions
 * @param {string} [interactionData.action_context] - set for certain click events
 */
function logInteraction( action, interactionData ) {
	mw.eventLog.submitInteraction(
		'product_metrics.web_base.article_summaries',
		'/analytics/product_metrics/web/base/1.3.1',
		action,
		interactionData
	);
}

module.exports = {
	logInteraction
};
