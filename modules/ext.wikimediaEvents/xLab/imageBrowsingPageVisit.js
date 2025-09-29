/**
 * Instrument that fires a `page-visited` event to Reader Growth's ImageBrowsing stream
 * if the current user loads a page
 * and is enrolled in either the treatment or the control group
 * of the corresponding A/B test.
 *
 * The event is used to compute user retention as a guardrail metric,
 * see instrumentation spec at
 * https://docs.google.com/spreadsheets/d/1ZqmKZm7-hdZ1HPDx8KSgZPUaJx7l-Wcd6i7sM5ZpfNI/edit?gid=0#gid=0.
 *
 * The stream should validate against the default Web base schema,
 * but it's explicitly set here for consistency with the ReaderExperiments extension:
 * https://github.com/wikimedia/mediawiki-extensions-ReaderExperiments/blob/7e563b4c8f06491d523eb3a45c323bf221c489b8/resources/ext.readerExperiments.imageBrowsing/init.js#L13
 */

const EXPERIMENT_NAME = 'fy2025-26-we3.1-image-browsing-ab-test';
const SCHEMA_NAME = '/analytics/product_metrics/web/base/1.4.3';
const STREAM_NAME = 'mediawiki.product_metrics.readerexperiments_imagebrowsing';
const INSTRUMENT_NAME = 'PageVisit';

mw.loader.using( 'ext.xLab' ).then( () => {
	const experiment = mw.xLab.getExperiment( EXPERIMENT_NAME );

	experiment.setSchema( SCHEMA_NAME );
	experiment.setStream( STREAM_NAME );
	experiment.send(
		'page-visited',
		{ instrument_name: INSTRUMENT_NAME }
	);
} );
