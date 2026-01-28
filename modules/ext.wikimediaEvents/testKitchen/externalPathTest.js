/**
 * A synthetic experiment to test the new external path that Test Kitchen will be sending events
 * originating from instruments and logged-in experiments to.
 */

const EXPERIMENT_ID = 'synth-test-new-external-path';
const INSTRUMENT_ID = 'synth-test-external-path';
const ACTION = 'page_visit';
const SCHEMA_ID = '/analytics/product_metrics/web/base/2.0.0';
const STREAM_NAME = 'product_metrics.web_base';
const OLD_EXTERNAL_PATH = require( './externalPathTestConfig.json' ).EventLoggingServiceUri;

mw.loader.using( 'ext.testKitchen' ).then( () => {
	const experiment = mw.testKitchen.getExperiment( EXPERIMENT_ID );
	const instrument = mw.testKitchen.getInstrument( INSTRUMENT_ID );

	if ( experiment.isAssignedGroup( 'control', 'treatment' ) ) {

		// Send event via the new external path:
		instrument.submitInteraction( ACTION, {
			action_context: 'new_external_path'
		} );

		// Send event manually via the old external path:
		const isMobileFrontendActive = mw.config.get( 'wgMFMode' ) !== null;

		const eventData = {
			$schema: SCHEMA_ID,
			meta: {
				stream: STREAM_NAME,
				domain: window.location.hostname
			},
			agent: {
				client_platform: 'mediawiki_js',

				// https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/EventLogging/+/de06180f92aa89802d50fa1b9d2165f483022ac8/modules/ext.eventLogging.metricsPlatform/MediaWikiMetricsClientIntegration.js#69
				client_platform_family: isMobileFrontendActive ? 'mobile_browser' : 'desktop_browser'
			},
			action: ACTION,
			action_context: 'old_external_path',
			instrument_name: INSTRUMENT_ID,
			performer: {
				// https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/EventLogging/+/de06180f92aa89802d50fa1b9d2165f483022ac8/modules/ext.eventLogging.metricsPlatform/MediaWikiMetricsClientIntegration.js#62
				is_logged_in: !mw.user.isAnon()
			}
		};
		navigator.sendBeacon( OLD_EXTERNAL_PATH, JSON.stringify( eventData ) );
	}
} );
