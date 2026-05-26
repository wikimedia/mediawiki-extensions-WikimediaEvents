/**
 * This experiment is used to detect "hoisting errors" – an error triggered by
 * a mismatch between the `experiment.enrolled` and `.assigned` event fields
 * and the internal experiment enrollment header sent by Varnish to EventGate,
 * the event intake service.
 *
 * See https://phabricator.wikimedia.org/T427092 and its associated tasks for
 * more details.
 *
 * History
 * =======
 *
 * 2026-05-26:
 * This experiment was used to test the ability to run non-cache-splitting
 * experiments via Test Kitchen. See https://phabricator.wikimedia.org/T419514
 * and its parent task for more details.
 */

const EXPERIMENT_NAME = 'synth-aa-detect-hoisting-errors-1';

mw.testKitchen.getExperiment( EXPERIMENT_NAME ).then(
	( e ) => {

		// NOTE: This experiment will not be analyzed with GrowthBook so
		// there's no need to send an exposure event.

		e.send( 'page_visit' );
	}
);
