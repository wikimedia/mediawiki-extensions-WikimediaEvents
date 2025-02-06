/* eslint-env qunit */
'use strict';

QUnit.module( 'ext.wikimediaEvents/searchSatisfaction', QUnit.newMwEnvironment() );

const searchSli = require( 'ext.wikimediaEvents/searchSatisfaction/searchSli.js' );
QUnit.test( 'searchSli', function ( assert ) {
	this.sandbox.spy( mw, 'trackSubscribe' );
	searchSli();
	assert.strictEqual(
		mw.trackSubscribe.callCount,
		2,
		'Two subscribe events were called'
	);

	const calls = mw.trackSubscribe.getCalls();
	assert.strictEqual(
		calls[ 0 ].args[ 0 ],
		'mediawiki.searchSuggest'
	);
	assert.strictEqual(
		calls[ 1 ].args[ 0 ],
		'mw.widgets.SearchInputWidget'
	);
} );
