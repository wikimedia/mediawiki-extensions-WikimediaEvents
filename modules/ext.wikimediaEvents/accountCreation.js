const useAccountCreationInstrument = require( './accountCreation/useAccountCreationInstrument.js' );
const { EXPERIMENTS } = require( './accountCreation/experiments.js' );
// Configure per experiment if needed
const STREAM_NAME = 'mediawiki.product_metrics.contributors.experiments';
const READING_LISTS_EXPERIMENT_NAME = 'account-creation-reading-list-cta';

module.exports = function init() {
	if ( mw.config.get( 'wgTKAccountJustCreated' ) ) {
		EXPERIMENTS.forEach( ( expName ) => useAccountCreationInstrument( expName, STREAM_NAME ) );
	}
	if ( mw.config.get( 'wgReadingListsAccountJustCreated' ) ) {
		useAccountCreationInstrument( READING_LISTS_EXPERIMENT_NAME, STREAM_NAME );
	}
};
