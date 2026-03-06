/**
 * Used to measure logged-out retention on a monthly basis.
 *
 * See:
 *
 * * [T409190 Start new A/A test for retenion](https://phabricator.wikimedia.org/T409190)
 * * [T419191 Start new A/A test for reader retention for March 2026](https://phabricator.wikimedia.org/T419191)
 *
 * History
 * =======
 *
 * 2026-03-06:
 * The instrument was repurposed to measure logged-out reader retention on a monthly basis. It was
 * renamed from `pageVisit.js` to `loggedOutReaderRetention.js` to reflect this.
 *
 * 2026-02-05:
 * This instrument was resurrected and repurposed for the "synth-aaa-test-mw-js" experiment. This
 * experiment validates the ability to run A/A/A experiments.
 *
 * 2025-08-07:
 * This instrument was repurposed for the "synth-aa-test-mw-js" experiment. See
 * https://phabricator.wikimedia.org/T397140 for more context.
 *
 * 2025-06-27:
 * This instrument was repurposed for the "sds2-4-11-synth-aa-test-2" experiment. See
 * https://phabricator.wikimedia.org/T397138 for more context.
 *
 * Previously:
 * This instrument was used for the "sds2-4-11-synth-aa-test-2" experiment. See
 * https://phabricator.wikimedia.org/T393918 and its parent task
 * https://phabricator.wikimedia.org/T392313 for more context.
 */

// e.g. logged-out-retention-round1, logged-out-retention-round2, etc.
const EXPERIMENT_NAME_PREFIX = 'logged-out-retention-';

mw.loader.using( 'ext.testKitchen' ).then( () => {
	mw.testKitchen.getExperimentByPrefix( EXPERIMENT_NAME_PREFIX )
		.send(
			'page-visited',
			{
				instrument_name: 'LoggedOutReaderRetention'
			}
		);
} );
