/* eslint-env qunit */

'use strict';

const sessionLengthMixin = require( 'ext.wikimediaEvents/sessionLength/mixin.js' );

QUnit.module( 'ext.wikimediaEvents/sessionLength/mixin', QUnit.newMwEnvironment() );

QUnit.test( 'Start sessionLength Tracking', ( assert ) => {
	const streamName = 'testStream';
	const schemaID = 'testSchema';
	const data = {};
	const expected = { schemaID, data };
	sessionLengthMixin.start( streamName, schemaID );
	assert.strictEqual( sessionLengthMixin.state.has( streamName ), true, `State should have '${ streamName }'` );
	assert.deepEqual( sessionLengthMixin.state.get( streamName ), expected, `Schema ID for '${ streamName }' should be '${ expected }'` );
} );

QUnit.test( 'Start sessionLength with data', ( assert ) => {
	const streamName = 'testStream';
	const schemaID = 'testSchema';
	const data = {
		testData: true
	};
	const expected = { schemaID, data };
	sessionLengthMixin.start( streamName, schemaID, data );
	assert.strictEqual( sessionLengthMixin.state.has( streamName ), true, `State should have '${ streamName }'` );
	assert.deepEqual( sessionLengthMixin.state.get( streamName ), expected, `Schema ID for '${ streamName }' should be '${ expected }'` );
} );

QUnit.test( 'Stop sessionLength Tracking', ( assert ) => {
	const streamName = 'testStream';
	sessionLengthMixin.start( streamName, 'testSchema', {} );
	sessionLengthMixin.stop( streamName );
	assert.strictEqual( sessionLengthMixin.state.has( streamName ), false, `State should not have '${ streamName }'` );
} );
