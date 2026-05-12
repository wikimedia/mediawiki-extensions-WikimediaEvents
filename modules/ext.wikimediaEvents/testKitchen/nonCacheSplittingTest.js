/**
 * This experiment tests the ability to run non-cache-splitting experiments via Test Kitchen.
 *
 * See https://phabricator.wikimedia.org/T419514 and its parent task for more details.
 */

const EXPERIMENT_NAME = 'synth-aa-ncs-1';

mw.testKitchen.getExperiment( EXPERIMENT_NAME ).then(
	( e ) => e.sendExposure()
);
