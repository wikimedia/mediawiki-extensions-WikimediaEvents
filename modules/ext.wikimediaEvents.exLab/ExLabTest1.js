const selector = '[data-pinnable-element-id="vector-main-menu"] .vector-pinnable-header-unpin-button';
const friendlyName = 'pinnable-header.vector-main-menu.unpin';
const experimentName = 'experimentation-lab-test-1-experiment';
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
		if ( !mw.eventLog.isCurrentUserEnrolled( experimentName ) ) {
			return;
		}
		const ClickThroughRateInstrument = require( './ClickThroughRateInstrument.js' );
		const instrument = mw.eventLog.newInstrument( experimentName, STREAM_NAME, SCHEMA_ID );
		ClickThroughRateInstrument.start( selector, friendlyName, instrument );
	}
};

module.exports = ExLabTest1;
