const useAccountCreationInstrument = require( './accountCreation/useAccountCreationInstrument.js' );
const { EXPERIMENTS } = require( './accountCreation/experiments.js' );
const READING_LISTS_EXPERIMENT_NAME = 'account-creation-reading-list-cta';

module.exports = function init() {
	if ( mw.config.get( 'wgTKAccountJustCreated' ) ) {
		EXPERIMENTS.forEach( ( expName ) => useAccountCreationInstrument( expName ) );
	}
	if ( mw.config.get( 'wgReadingListsAccountJustCreated' ) ) {
		useAccountCreationInstrument( READING_LISTS_EXPERIMENT_NAME );
	}
};
