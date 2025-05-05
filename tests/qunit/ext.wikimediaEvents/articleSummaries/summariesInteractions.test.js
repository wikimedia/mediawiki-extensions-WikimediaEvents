'use strict';

QUnit.module( 'ext.wikimediaEvents/articleSummaries/summariesInteractions', QUnit.newMwEnvironment() );

// Mock the module bc it's not available in the test environment
// since RL only loads it for Minerva skin
const summariesInteractions = {
	logInteraction: function ( action, interactionData ) {
		mw.eventLog.submitInteraction(
			'product_metrics.web_base.article_summaries',
			'/analytics/product_metrics/web/base/1.3.1',
			action,
			interactionData
		);
	}
};

QUnit.test( 'ensure we call the submitInteraction function properly', function ( assert ) {
	const stub = this.sandbox.stub( mw.eventLog, 'submitInteraction' );

	summariesInteractions.logInteraction( 'test action', 'test interaction data' );

	assert.true( stub.calledOnce );
	assert.true( stub.calledWithExactly(
		'product_metrics.web_base.article_summaries',
		'/analytics/product_metrics/web/base/1.3.1',
		'test action',
		'test interaction data'
	) );
} );
