'use strict';

const searchRecommendations = require( 'ext.wikimediaEvents/searchRecommendations/index.js' );

QUnit.module( 'ext.wikimediaEvents/searchRecommendations/init.js', QUnit.newMwEnvironment() );

QUnit.test( 'setupInstrumentation for different A/B test', function ( assert ) {
	const stub = this.sandbox.stub( mw.eventLog, 'submitInteraction' );
	searchRecommendations.test.setupInstrumentation( {
		group: '1',
		experimentName: 'otherexperiment'
	} );

	assert.false(
		stub.calledOnce,
		'if experiment name does not match no events fired'
	);
} );

QUnit.test( 'setupInstrumentation for our A/B test', function ( assert ) {
	const stub = this.sandbox.stub( mw.eventLog, 'submitInteraction' );
	mw.hook = this.sandbox.stub( mw, 'hook' ).returns( {
		add: this.sandbox.spy(),
		fire: this.sandbox.spy()
	} );
	searchRecommendations.test.setupInstrumentation( {
		group: '1',
		experimentName: 'RelatedArticles test experiment'
	} );

	assert.true(
		stub.calledOnce,
		'calling setupInstrumentation fires the init event'
	);
	[
		'ext.MobileFrontend.searchOverlay.open',
		'ext.MobileFrontend.searchOverlay.empty',
		'ext.MobileFrontend.searchOverlay.startQuery',
		'ext.relatedArticles.click',
		'ext.MobileFrontend.searchOverlay.click'
	].forEach( ( event ) => {
		assert.true(
			mw.hook.calledWithExactly( event ),
			'The hook was subscribed to'
		);
	} );
} );
