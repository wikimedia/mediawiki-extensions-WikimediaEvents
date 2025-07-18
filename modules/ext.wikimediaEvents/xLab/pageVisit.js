/**
 * A simple experiment-specific instrument that sends a "page-visited" event if the current user is
 * enrolled in the "sds2-4-11-synth-aa-test-2" experiment.
 *
 * History
 * =======
 *
 * 2025-06-27:
 *
 * * This instrument was repurposed for the "sds2-4-11-synth-aa-test-2" experiment. See
 *   https://phabricator.wikimedia.org/T397138 for more context.
 *
 * Previously:
 *
 * * This instrument was used for the "sds2-4-11-synth-aa-test-2" experiment. See
 *   https://phabricator.wikimedia.org/T393918 and its parent task
 *   https://phabricator.wikimedia.org/T392313 for more context.
 */

const EXPERIMENT_NAME = 'sds2-4-11-synth-aa-test-2';

mw.loader.using( 'ext.xLab' ).then( () => {
	mw.xLab.getExperiment( EXPERIMENT_NAME )
		.send(
			'page-visited',
			{
				instrument_name: 'PageVisit'
			}
		);
} );
