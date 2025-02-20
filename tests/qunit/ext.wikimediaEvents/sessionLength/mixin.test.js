/* eslint-env qunit */

'use strict';

const sessionLengthMixin = require( 'ext.wikimediaEvents/sessionLength/mixin.js' );

QUnit.module( 'ext.wikimediaEvents/sessionLength/mixin', QUnit.newMwEnvironment() );

QUnit.test( 'Initial tick fires at zero seconds', function ( assert ) {
	// setting config required for metrics platform.
	mw.config.set( 'wgUserGroups', [ '*', 'user' ] );
	// START mw.storage mock. Sets and gets an in-memory storage key.
	// Copied from mediawiki.storage.test.js in MediaWiki core.
	const storageData = {};
	const mwStorageStub = {
		setItem: function ( k, v ) {
			storageData[ k ] = v;
			return true;
		},
		getItem: function ( k ) {
			return Object.prototype.hasOwnProperty.call( storageData, k ) ? storageData[ k ] : null;
		},
		removeItem: function ( k ) {
			delete storageData[ k ];
			return true;
		},
		key: function ( i ) {
			return Object.keys( storageData )[ i ];
		}
	};
	Object.defineProperty( mwStorageStub, 'length', {
		get: function () {
			return Object.keys( storageData ).length;
		}
	} );
	this.sandbox.stub( mw.storage, 'store', mwStorageStub );
	// END mw.storage mock.

	mw.storage.set( 'mp-sessionTickTickCount', null );
	const streamName = 'testStream';
	const schemaID = 'testSchema';
	sessionLengthMixin.start( streamName, schemaID );
	assert.strictEqual( Number( mw.storage.get( 'mp-sessionTickTickCount' ) ), 1,
		'First tick should set count to 1'
	);
} );

QUnit.test( 'Start sessionLength Tracking', ( assert ) => {
	const streamName = 'testStream';
	const schemaID = 'testSchema';
	const data = {};
	const expected = { schemaID, data };
	mw.config.set( 'wgUserGroups', [ '*', 'user' ] );
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
