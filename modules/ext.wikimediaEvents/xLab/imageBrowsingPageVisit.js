/**
 * Instrument that fires a `page-visited` event to Reader Growth's ImageBrowsing stream
 * if the current user loads a page
 * and is enrolled in either the treatment or the control group
 * of the corresponding A/B tests.
 *
 * The event is used to compute user retention as a guardrail metric,
 * see instrumentation spec at
 * https://docs.google.com/spreadsheets/d/1ZqmKZm7-hdZ1HPDx8KSgZPUaJx7l-Wcd6i7sM5ZpfNI/edit?gid=0#gid=0.
 *
 * The stream should validate against the default Web base schema,
 * but it's explicitly set here for consistency with the ReaderExperiments extension:
 * https://github.com/wikimedia/mediawiki-extensions-ReaderExperiments/blob/7e563b4c8f06491d523eb3a45c323bf221c489b8/resources/ext.readerExperiments.imageBrowsing/init.js#L13
 */

// Tier 1: 10% Arabic, Chinese, French, Indonesian, and Vietnamese Wikipedias.
// https://mpic.wikimedia.org/experiment/fy2025-26-we3.1-image-browsing-ab-test
const TIER_ONE_EXPERIMENT_NAME = 'fy2025-26-we3.1-image-browsing-ab-test';
// Tier 2: 0.1% English Wikipedia.
// https://mpic.wikimedia.org/experiment/image-browsing-enwiki
const TIER_TWO_EXPERIMENT_NAME = 'image-browsing-enwiki';

const SCHEMA_NAME = '/analytics/product_metrics/web/base/1.4.3';
const STREAM_NAME = 'mediawiki.product_metrics.readerexperiments_imagebrowsing';
const INSTRUMENT_NAME = 'PageVisit';

function firePageVisit( experimentName ) {
	const experiment = mw.xLab.getExperiment( experimentName );
	experiment.setSchema( SCHEMA_NAME );
	experiment.setStream( STREAM_NAME );
	experiment.send(
		'page-visited',
		{ instrument_name: INSTRUMENT_NAME }
	);
}

mw.loader.using( 'ext.xLab' ).then( () => {
	firePageVisit( TIER_ONE_EXPERIMENT_NAME );
	firePageVisit( TIER_TWO_EXPERIMENT_NAME );
} );
