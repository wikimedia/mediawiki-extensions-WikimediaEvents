/**
 * Experiment-specific instrument that sends a `page-visited` event if the user is enrolled in the experiment below
 * The purpose of the experiment is to measure the impact of incremental increase in traffic for cache splitting experiments
 * Once the impact test finished, this experiment will be deleted
 * See https://phabricator.wikimedia.org/T407570 for more details
 */

const EXPERIMENT_NAME = 'synth-aa-test-traffic-impact';
const INSTRUMENT_NAME = 'PageVisit';

mw.loader.using( 'ext.testKitchen' ).then( () => {
	const experiment = mw.testKitchen.getExperiment( EXPERIMENT_NAME );

	experiment.send(
		'page-visited',
		{ instrument_name: INSTRUMENT_NAME }
	);
} );
