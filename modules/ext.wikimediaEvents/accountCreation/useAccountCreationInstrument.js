const removeQueryParam = require( './removeQueryParam.js' );
const useAccountCreationInstrument = (
	experimentName,
	streamName = null,
	schemaId = null
) => {
	if ( !experimentName ) {
		throw new Error( 'Experiment name is required' );
	}
	return mw.loader.using( [ 'ext.testKitchen' ] ).then( () => {
		const experiment = mw.testKitchen.getExperiment( experimentName );
		if ( schemaId ) {
			experiment.setSchema( schemaId );
		}
		if ( streamName ) {
			experiment.setStream( streamName );
		}

		experiment.send( 'account_created' );
		removeQueryParam( new URL( window.location.href ), 'accountJustCreated' );
		return experiment;
	} ).catch( ( error ) => {
		mw.log( 'Error loading ext.testKitchen module:', error );
		return null;
	} );
};

module.exports = useAccountCreationInstrument;
