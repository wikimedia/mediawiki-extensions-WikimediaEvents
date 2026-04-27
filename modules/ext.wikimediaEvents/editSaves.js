const { EXPERIMENTS } = require( './accountCreation/experiments.js' );
// TODO: More experiments can be added here.

/**
 * @param {TestKitchenExperiment[]} configuredExperiments
 * @param {number} newRevId
 */
function submitEditInteraction( configuredExperiments, newRevId ) {
	configuredExperiments.forEach( ( experiment ) => {
		experiment.send( 'edit_saved', {
			page: {
				namespace_id: mw.config.get( 'wgNamespaceNumber' ),
				revision_id: newRevId
			}
		} );
	} );
}

mw.loader.using( [
	'ext.testKitchen',
	'ext.wikimediaEvents.testKitchen'
] ).then( () => {
	if ( !mw.config.get( 'wgMFMode' ) ) {
		// For now, this is mobile-only. Though it should be expanded
		// once there is a use-case for desktop as well.
		return;
	}
	const configuredExperiments = EXPERIMENTS.map(
		( experimentName ) => mw.testKitchen.getExperiment( experimentName )
	);
	mw.hook( 'mobileFrontend.sourceEditor.saveComplete' )
		.add( ( newRevId ) => submitEditInteraction( configuredExperiments, newRevId ) );

	mw.hook( 've.newTarget' ).add( ( target ) => {
		target.once( 'save',
			/**
			 * @param {{newrevid: number}} data
			 */
			( data ) => submitEditInteraction( configuredExperiments, data.newrevid )
		);
	} );

} );
