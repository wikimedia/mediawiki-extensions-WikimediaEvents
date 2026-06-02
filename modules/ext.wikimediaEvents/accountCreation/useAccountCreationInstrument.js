const removeQueryParam = require( './removeQueryParam.js' );
const useAccountCreationInstrument = (
	experimentName,
	schemaId = null
) => {
	if ( !experimentName ) {
		throw new Error( 'Experiment name is required' );
	}
	return mw.testKitchen.getExperiment( experimentName )
		.then( ( experiment ) => {
			if ( schemaId ) {
				experiment.setSchema( schemaId );
			}

			experiment.send( 'account_created' );
			removeQueryParam( new URL( window.location.href ), [
				'accountJustCreated',
				'readingListsAccountJustCreated'
			] );
			return experiment;
		} );
};

module.exports = useAccountCreationInstrument;
