/**
 * Reusable instrumentation for producing edit_saved events
 * from experiments conducted with Test Kitchen.
 *
 * Usage:
 * ```
 * // Assuming `experiment` is an instance of mw.testKitchen.Experiment
 * const editSaved = require( './editSaved.js' );
 * experiment.use( editSaved );
 * ```
 *
 * If used as-is, this is a low risk data collection. Risk level
 * may increase with inclusion of certain contextual attributes
 * at experiment/instrument level.
 *
 * See also:
 * - ./accountCreation/accountCreated.js
 * - ./pageVisit.js
 */

const EDIT_SAVED_ACTION = 'edit_saved';
const EDIT_SAVED_ATTRIBUTES = [
	'mediawiki_database', // Required for joining with edit_survived events
	'page_namespace_id' // Required for 'Average article edits saved' metric
];

/**
 * @param {mw.testKitchen.EventSenderInterface} eventSender
 * @param {number} newRevId
 */
function submitEditInteraction( eventSender, newRevId ) {
	/**
	 * The revision ID is set manually and is recorded specifically for these events
	 * rather than at instrument/experiment level (by selecting the page_revision_id
	 * contextual attribute when configuring) to avoid creating a reading log for
	 * specific users.
	 */
	eventSender.send( EDIT_SAVED_ACTION, {
		page: {
			revision_id: newRevId
		}
	}, EDIT_SAVED_ATTRIBUTES );
}

/**
 * @param {mw.testKitchen.EventSenderInterface} eventSender
 * Note: if we plan to re-use this in a bunch of experiments, we need to optimize it.
 */
function editSaved( eventSender ) {
	mw.hook( 'mobileFrontend.sourceEditor.saveComplete' )
		.add( ( newRevId ) => submitEditInteraction( eventSender, newRevId ) );
	mw.hook( 've.newTarget' ).add( ( target ) => {
		target.once( 'save',
			( data ) => submitEditInteraction( eventSender, data.newrevid )
		);
	} );
}

module.exports = editSaved;
