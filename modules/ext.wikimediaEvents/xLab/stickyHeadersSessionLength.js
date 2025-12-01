/**
 * Instrument that fires a `tick` event to Reader Growth's StickyHeaders stream
 * if the current user is enrolled in either group of the corresponding A/B test.
 *
 * The event is used to compute session length as the primary metric,
 * see instrumentation spec at
 * https://docs.google.com/spreadsheets/d/13UZtboVSABm3ALPd7DUxASP5jTUJuagJNW9iXSw9Oxo/edit?gid=0#gid=0&range=16:16.
 */

const { SessionLengthInstrumentMixin } = require( '../sessionLength/mixin.js' );

// https://mpic.wikimedia.org/experiment/sticky-headers
const EXPERIMENT_NAME = 'sticky-headers';
const STREAM_NAME = 'mediawiki.product_metrics.readerexperiments_stickyheaders';
const INSTRUMENT_NAME = 'SessionLength';

mw.loader.using( [
	'ext.xLab',
	'ext.wikimediaEvents'
] ).then( () => {
	const experiment = mw.xLab.getExperiment( EXPERIMENT_NAME );
	experiment.setStream( STREAM_NAME );

	// The session length instrument automatically resets a session after 1 hour:
	// https://gerrit.wikimedia.org/g/mediawiki/extensions/WikimediaEvents/+/refs/changes/56/1212556/1/modules/ext.wikimediaEvents/sessionLength/mixin.js#23
	// No need to manually stop it.
	SessionLengthInstrumentMixin.start(
		experiment,
		// eslint-disable-next-line camelcase
		{ instrument_name: INSTRUMENT_NAME }
	);
} ).catch( ( error ) => {
	// xLab and/or wikimediaEvents aren't installed,
	// instrumentation can't work.
	// eslint-disable-next-line no-console
	console.error( `[StickyHeaders] Failed to setup instrumentation. ${ error }` );
} );
