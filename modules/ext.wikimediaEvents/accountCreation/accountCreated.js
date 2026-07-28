/**
 * Reusable instrumentation for producing account_created events
 * from experiments conducted with Test Kitchen.
 *
 * Usage:
 * ```
 * // Assuming `experiment` is an instance of mw.testKitchen.Experiment
 * const accountCreated = require( './accountCreation/accountCreated.js' );
 * experiment.use( accountCreated );
 * ```
 *
 * If used as-is, this is a low risk data collection. Risk level
 * may increase with inclusion of certain contextual attributes
 * at experiment/instrument level.
 *
 * See also:
 * - ../editSaved.js
 * - ../pageVisit.js
 */

const ACCOUNT_CREATED_ACTION = 'account_created';
const ACCOUNT_CREATED_ATTRIBUTES = [
	'performer_is_temp' // Required for 'Permanent account creation rate' metric
];

const removeQueryParam = require( './removeQueryParam.js' );

/**
 * @param {mw.testKitchen.EventSenderInterface} eventSender
 */
function accountCreated( eventSender ) {
	// AccountCreation/AccountCreationHandler.php sets wgTKAccountJustCreated JS config var
	if ( mw.config.get( 'wgTKAccountJustCreated' ) ) {
		eventSender.send( ACCOUNT_CREATED_ACTION, {}, ACCOUNT_CREATED_ATTRIBUTES );
		removeQueryParam( new URL( window.location.href ), [
			'accountJustCreated'
		] );
	}
}

module.exports = accountCreated;
