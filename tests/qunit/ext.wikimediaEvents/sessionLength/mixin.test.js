/* eslint-env qunit */

'use strict';

const sessionLengthMixin = require( 'ext.wikimediaEvents/sessionLength/mixin.js' );

QUnit.module( 'ext.wikimediaEvents/sessionLength/mixin', QUnit.newMwEnvironment() );

QUnit.test( 'Start sessionLength Tracking', ( assert ) => {
	const streamName = 'testStream';
	const schemaID = 'testSchema';
	sessionLengthMixin.enabled = true;
	sessionLengthMixin.start( streamName, schemaID );
	assert.strictEqual( sessionLengthMixin.state.has( streamName ), true, `State should have '${ streamName }'` );
	assert.strictEqual( sessionLengthMixin.state.get( streamName ), schemaID, `Schema ID for '${ streamName }' should be '${ schemaID }'` );
} );

QUnit.test( 'Stop sessionLength Tracking', ( assert ) => {
	const streamName = 'testStream';
	sessionLengthMixin.enabled = true;
	sessionLengthMixin.start( streamName, 'testSchema' );
	sessionLengthMixin.stop( streamName );
	assert.strictEqual( sessionLengthMixin.state.has( streamName ), false, `State should not have '${ streamName }'` );
} );
