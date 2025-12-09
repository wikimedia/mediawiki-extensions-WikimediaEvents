const { SessionLengthInstrumentMixin } = require( '../sessionLength/mixin.js' );

// https://mpic.wikimedia.org/experiment/sticky-headers
// Default Web base schema.
const EXPERIMENT_NAME = 'sticky-headers';
const STREAM_NAME = 'mediawiki.product_metrics.readerexperiments_stickyheaders';
const TREATMENT_GROUP_NAME = 'treatment';

const pageLengthBucket = mw.config.get( 'wgWMEPageLengthBucket', -1 );

/**
 * Instrument that fires a `tick` event to Reader Growth's StickyHeaders stream
 * if the current user is enrolled in either group of the corresponding A/B test.
 *
 * The event is used to compute session length as the primary metric,
 * see instrumentation spec at
 * https://docs.google.com/spreadsheets/d/13UZtboVSABm3ALPd7DUxASP5jTUJuagJNW9iXSw9Oxo/edit?gid=0#gid=0&range=16:16.
 *
 * @param {mw.xLab.Experiment} experiment
 */
function trackSessionLength( experiment ) {
	// The session length instrument automatically resets a session after 1 hour:
	// https://gerrit.wikimedia.org/g/mediawiki/extensions/WikimediaEvents/+/refs/changes/56/1212556/1/modules/ext.wikimediaEvents/sessionLength/mixin.js#23
	// No need to manually stop it.
	SessionLengthInstrumentMixin.start(
		experiment,
		{
			instrument_name: 'SessionLength'
		}
	);
}

/**
 * Instrument that fires a `page-visited` event to Reader Growth's StickyHeaders stream
 * if the current user is enrolled in either group of the corresponding A/B test.
 *
 * The event is used to compute retention rate as guardrail metric,
 * see instrumentation spec at
 * https://docs.google.com/spreadsheets/d/13UZtboVSABm3ALPd7DUxASP5jTUJuagJNW9iXSw9Oxo/edit?gid=0#gid=0&range=28:28.
 *
 * @param {mw.xLab.Experiment} experiment
 */
function trackPageVisit( experiment ) {
	experiment.send(
		'page-visited',
		{
			instrument_name: 'PageVisit',
			action_context: pageLengthBucket
		}
	);
}

/**
 * Instrument that fires a `click` event to Reader Growth's StickyHeaders stream
 * if the current user is enrolled in either group of the corresponding A/B test,
 * and they toggle section visibility.
 *
 * The event is used to compute number and proportion of interactions as secondary metric,
 * see instrumentation spec at
 * https://docs.google.com/spreadsheets/d/13UZtboVSABm3ALPd7DUxASP5jTUJuagJNW9iXSw9Oxo/edit?gid=0#gid=0&range=20:24.
 *
 * @param {mw.xLab.Experiment} experiment
 */
function trackSectionHeaderClicks( experiment ) {
	const trackSectionHeaderClick = ( options ) => {
		// action_source differentiates between sticky & non-sticky headers;
		// a sticky header is one that is positioned at the top or even (partially)
		// off-screen (when it is moving out of the way to make place for the next
		// section) - otherwise, it is just another header mid-screen, not sticky
		const isSticky = experiment.isAssignedGroup( TREATMENT_GROUP_NAME ) &&
			options.heading.getBoundingClientRect().top < 1;

		experiment.send(
			'click',
			{
				instrument_name: 'SectionHeaderClick',
				action_subtype: options.isExpanded ? 'unfold' : 'fold',
				action_source: isSticky ? 'sticky_section_header' : 'section_header',
				action_context: pageLengthBucket
			}
		);
	};

	mw.hook( 'wikipage.content' ).add( () => {
		// We don't want to record every single toggle, because there are
		// a bunch of those automatically happening on page load depending
		// on various conditions (window size, user preference, extension
		// overrides, anchor links, ...). I set out to replicate those
		// conditions in order to filter these out, but I am less than
		// confident that it would not allow some edge cases through.
		// Instead, I think we can simply ignore all toggles that happen
		// (almost) immediately after page load (and keep extending that
		// until none have happened for long enough, just in case some
		// late scripts kick in to toggle some more after the initial setup
		// (e.g. expanding sections from window.location.hash)
		// 300ms feels like a pretty good duration in which all of this
		// code should have been able to run, while still being radically
		// too short for any human to consciously decide to toggle a section.
		let allowToggleTracking = false;
		const allowToggleTrackingAfterTimeout = mw.util.debounce(
			() => ( allowToggleTracking = true ),
			300
		);
		allowToggleTrackingAfterTimeout();

		mw.hook( 'readerExperiments.section-toggled' ).add( ( options ) => {
			if ( allowToggleTracking ) {
				trackSectionHeaderClick( options );
			} else {
				allowToggleTrackingAfterTimeout();
			}
		} );
	} );
}

mw.loader.using( [
	'ext.xLab',
	'ext.wikimediaEvents'
] ).then( () => {
	const experiment = mw.xLab.getExperiment( EXPERIMENT_NAME );
	experiment.setStream( STREAM_NAME );

	trackSessionLength( experiment );
	trackPageVisit( experiment );
	trackSectionHeaderClicks( experiment );
} ).catch( ( error ) => {
	// xLab and/or wikimediaEvents aren't installed,
	// instrumentation can't work.
	// eslint-disable-next-line no-console
	console.error( `[StickyHeaders] Failed to setup instrumentation. ${ error }` );
} );
