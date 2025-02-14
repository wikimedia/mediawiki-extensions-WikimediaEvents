/* eslint-env qunit */
'use strict';

QUnit.module( 'ext.wikimediaEvents/searchSatisfaction', QUnit.newMwEnvironment() );

const searchSatisfaction = require( 'ext.wikimediaEvents/searchSatisfaction/searchSatisfaction.js' );
QUnit.test( 'searchSatisfaction', ( assert ) => {
	searchSatisfaction();
	assert.true(
		true,
		'Search satisfaction executed without error.'
	);
} );
