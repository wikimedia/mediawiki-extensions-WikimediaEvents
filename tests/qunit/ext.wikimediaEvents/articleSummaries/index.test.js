'use strict';

QUnit.module( 'ext.wikimediaEvents/articleSummaries/index', QUnit.newMwEnvironment() );

const articleSummaries = require( 'ext.wikimediaEvents/articleSummaries/index.js' );

QUnit.test( 'hasOptedInToSummaries', function ( assert ) {
	assert.false( articleSummaries.test.hasOptedInToSummaries() );

	// it appears the user is logged in in tests, so we must stub
	mw.user.clientPrefs.get = this.sandbox.stub( mw.user.clientPrefs, 'get' ).returns( '1' );

	assert.true( articleSummaries.test.hasOptedInToSummaries() );
} );

QUnit.test( 'setupInstrumentation', function ( assert ) {
	const stub = this.sandbox.stub( mw.eventLog, 'submitInteraction' );

	// test the DOM query
	const div = document.createElement( 'div' );
	div.className = 'ext-article-summaries-container';
	document.body.appendChild( div );

	// test init
	articleSummaries.test.setupInstrumentation( stub );
	assert.true( stub.calledOnce );
	assert.true( stub.calledWithExactly(
		'init',
		{
			// eslint-disable-next-line camelcase
			action_subtype: 'article_summary_link'
		}
	) );

	// test hooks
	mw.hook( 'ext.articleSummaries.summary.opened' ).fire();
	assert.true( stub.calledTwice );
	assert.true( stub.calledWithExactly(
		'click',
		{
			// eslint-disable-next-line camelcase
			action_subtype: 'read_summary',
			// eslint-disable-next-line camelcase
			action_source: 'article'
		}
	) );

	mw.hook( 'ext.articleSummaries.summary.shown' ).fire();
	assert.true( stub.calledThrice );
	assert.true( stub.calledWithExactly(
		'show',
		{
			// eslint-disable-next-line camelcase
			action_subtype: 'show_article_summary'
		}
	) );

	mw.hook( 'ext.articleSummaries.summary.yesButton' ).fire();
	assert.strictEqual( stub.callCount, 4 );
	assert.true( stub.calledWithExactly(
		'click',
		{
			// eslint-disable-next-line camelcase
			action_subtype: 'yes',
			// eslint-disable-next-line camelcase
			action_context: 'summaryHelpfulnessCheck',
			// eslint-disable-next-line camelcase
			action_source: 'article_summary'
		}
	) );

	mw.hook( 'ext.articleSummaries.summary.noButton' ).fire();
	assert.strictEqual( stub.callCount, 5 );
	assert.true( stub.calledWithExactly(
		'click',
		{
			// eslint-disable-next-line camelcase
			action_subtype: 'no',
			// eslint-disable-next-line camelcase
			action_context: 'summaryHelpfulnessCheck',
			// eslint-disable-next-line camelcase
			action_source: 'article_summary'
		}
	) );

	// instrumentation has already been enabled so init event should not fire again
	articleSummaries.test.setupInstrumentation( stub );
	assert.strictEqual( stub.callCount, 5 );

} );
