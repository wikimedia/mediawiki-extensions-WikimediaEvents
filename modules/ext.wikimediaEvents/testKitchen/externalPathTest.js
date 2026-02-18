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
const NEW_EXTERNAL_PATH = require( './externalPathTestConfig.json' ).TestKitchenInstrumentEventIntakeServiceUrl;

if ( typeof NEW_EXTERNAL_PATH === 'undefined' ) {
	return;
}

mw.loader.using( 'ext.testKitchen' ).then( () => {
	const experiment = mw.testKitchen.getExperiment( EXPERIMENT_ID );
	const instrument = mw.testKitchen.getInstrument( INSTRUMENT_ID );
	const timezoneOffset = new Date().getTimezoneOffset();

	if ( experiment.isAssignedGroup( 'control', 'treatment' ) ) {

		//
		const actionContextTK = {
			tk_sdk: true,
			ext_path: 'new',
			tz_offset: timezoneOffset
		};

		// Send event via the new external path:
		instrument.submitInteraction( ACTION, {
			action_context: JSON.stringify( actionContextTK )
		} );

		// Send event manually via the old external path:
		const isMobileFrontendActive = mw.config.get( 'wgMFMode' ) !== null;

		const actionContextOldPath = {
			tk_sdk: false,
			ext_path: 'old',
			tz_offset: timezoneOffset
		};

		const eventDataOld = {
			$schema: SCHEMA_ID,
			meta: {
				stream: STREAM_NAME,
				domain: window.location.hostname
			},
			agent: {
				client_platform: 'mediawiki_js',

				// https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/EventLogging/+/de06180f92aa89802d50fa1b9d2165f483022ac8/modules/ext.eventLogging.metricsPlatform/MediaWikiMetricsClientIntegration.js#69
				client_platform_family: isMobileFrontendActive ? 'mobile_browser' : 'desktop_browser',
				ua_string: navigator.userAgent
			},
			action: ACTION,
			action_context: JSON.stringify( actionContextOldPath ),
			instrument_name: INSTRUMENT_ID,
			performer: {
				// https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/EventLogging/+/de06180f92aa89802d50fa1b9d2165f483022ac8/modules/ext.eventLogging.metricsPlatform/MediaWikiMetricsClientIntegration.js#62
				is_logged_in: !mw.user.isAnon()
			}
		};
		navigator.sendBeacon( OLD_EXTERNAL_PATH, JSON.stringify( eventDataOld ) );

		// Send event manually to the new external path, T417068:
		const actionContextNewPath = {
			tk_sdk: false,
			ext_path: 'new',
			tz_offset: timezoneOffset
		};

		const eventDataNew = {
			$schema: SCHEMA_ID,
			meta: {
				stream: STREAM_NAME,
				domain: window.location.hostname
			},
			agent: {
				client_platform: 'mediawiki_js',
				client_platform_family: isMobileFrontendActive ? 'mobile_browser' : 'desktop_browser',
				ua_string: navigator.userAgent
			},
			action: ACTION,
			action_context: JSON.stringify( actionContextNewPath ),
			instrument_name: INSTRUMENT_ID,
			performer: {
				is_logged_in: !mw.user.isAnon()
			}
		};

		navigator.sendBeacon( NEW_EXTERNAL_PATH, JSON.stringify( eventDataNew ) );

	}
} );
