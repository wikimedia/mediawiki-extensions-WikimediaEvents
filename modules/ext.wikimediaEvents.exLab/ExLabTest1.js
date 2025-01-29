const userExperiments = mw.config.get( 'wgMetricsPlatformUserExperiments' );
const selector = '[data-pinnable-element-id="vector-main-menu"] .vector-pinnable-header-unpin-button';
const friendlyName = 'pinnable-header.vector-main-menu.unpin';
const experimentName = 'experimentation-lab-test-1-experiment';
const featureName = 'experimentation_lab_test_1_feature';
const STREAM_NAME = 'product_metrics.web_base';
const SCHEMA_ID = '/analytics/product_metrics/web/base/1.3.0';

/**
 * Experimentation Lab's first test module
 *
 * This module includes the ClickThroughRateInstrument for testing A/B test enrollment.
 *
 * Note this is temporary code - it will be removed once we validate data collection (T383801).
 */
const ExLabTest1 = {

	init() {
		if ( !mw.eventLog.isCurrentUserEnrolled( experimentName ) ||
			!( featureName in userExperiments.assigned ) ) {
			return;
		}
		const ClickThroughRateInstrument = require( './ClickThroughRateInstrument.js' );

		if ( userExperiments.assigned[ featureName ] === 'true' ) {
			const instrument = mw.eventLog.newInstrument( STREAM_NAME, SCHEMA_ID );
			instrument.setInstrumentName( experimentName );
			ClickThroughRateInstrument.start( selector, friendlyName, instrument );
		}
	}
};

module.exports = ExLabTest1;
