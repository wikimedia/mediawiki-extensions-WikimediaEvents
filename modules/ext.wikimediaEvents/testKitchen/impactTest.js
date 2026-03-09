/**
 * Experiment-specific instrument that sends a `page-visited` event if the user is enrolled in the experiment below
 * The purpose of the experiment is to measure the impact of incremental increase in traffic for cache splitting experiments
 *
 * Once the impact test finished, this experiment will be deleted
 * See https://phabricator.wikimedia.org/T407570 for more details
 */

const EXPERIMENT_NAME = 'synth-aa-test-traffic-impact-';
const EXPERIMENT_NAME_1 = EXPERIMENT_NAME + '1';
const EXPERIMENT_NAME_2 = EXPERIMENT_NAME + '2';
const EXPERIMENT_NAME_3 = EXPERIMENT_NAME + '3';
const INSTRUMENT_NAME = 'PageVisit';

mw.loader.using( 'ext.testKitchen' ).then( () => {
	[ EXPERIMENT_NAME_1, EXPERIMENT_NAME_2, EXPERIMENT_NAME_3 ].forEach( ( experimentName ) => {
		mw.testKitchen.getExperiment( experimentName )
			.send(
				'page-visited',
				{ instrument_name: INSTRUMENT_NAME }
			);
	} );
} );
