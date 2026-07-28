const EXPERIMENT_NAME_PREFIX = 'tk-aa-test-edit-saved-';
const editSaved = require( '../editSaved.js' );

mw.testKitchen.getExperimentsByPrefix( EXPERIMENT_NAME_PREFIX ).then(
	( experiments ) => {
		experiments.forEach( ( experiment ) => {
			experiment.sendExposure();
			experiment.use( editSaved );
		} );
	}
);
