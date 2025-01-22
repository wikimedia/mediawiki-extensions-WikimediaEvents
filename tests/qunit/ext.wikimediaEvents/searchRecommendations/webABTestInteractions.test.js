'use strict';

const webABTestInteractions = require( 'ext.wikimediaEvents/searchRecommendations/webABTestInteractions.js' );

QUnit.module( 'ext.wikimediaEvents/searchRecommendations/webABTestInteractions', QUnit.newMwEnvironment() );

QUnit.test( 'ensure we call the submitInteraction function properly', function ( assert ) {
	const stub = this.sandbox.stub( mw.eventLog, 'submitInteraction' );

	webABTestInteractions.logInteraction( 'test action', 'test interaction data' );

	assert.true( stub.calledOnce );
	assert.true( stub.calledWithExactly(
		'product_metrics.web_base.search_ab_test_clicks',
		'/analytics/product_metrics/web/base/1.3.0',
		'test action',
		'test interaction data'
	) );
} );
