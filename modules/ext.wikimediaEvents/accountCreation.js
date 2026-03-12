const useAccountCreationInstrument = require( './accountCreation/useAccountCreationInstrument.js' );
const { EXPERIMENTS } = require( './accountCreation/experiments.js' );
// Configure per experiment if needed
const STREAM_NAME = 'mediawiki.product_metrics.contributors.experiments';

module.exports = function init() {
	if ( !mw.config.get( 'wgTKAccountJustCreated' ) ) {
		return;
	}
	EXPERIMENTS.forEach( ( expName ) => useAccountCreationInstrument( expName, STREAM_NAME ) );
};
