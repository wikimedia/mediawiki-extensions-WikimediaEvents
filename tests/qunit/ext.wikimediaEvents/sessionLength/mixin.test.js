/* eslint-env qunit */

'use strict';

const { SessionLengthInstrumentMixin } = require( 'ext.wikimediaEvents/sessionLength/mixin.js' );

QUnit.module( 'ext.wikimediaEvents/sessionLength/mixin', QUnit.newMwEnvironment() );

QUnit.test( 'Initial tick fires at zero seconds', function ( assert ) {
	// setting config required for metrics platform.
	mw.config.set( 'wgUserGroups', [ '*', 'user' ] );
	// START mw.storage mock. Sets and gets an in-memory storage key.
	// Copied from mediawiki.storage.test.js in MediaWiki core.
	const storageData = {};

	const mwStorageStub = {
		get: function ( k ) {
			return Object.prototype.hasOwnProperty.call( storageData, k ) ? storageData[ k ] : null;
		},
		getObject: () => {},
		set: function ( k, v ) {
			storageData[ k ] = v;
			return true;
		},
		setObject: () => {},
		remove: () => {}
	};

	this.sandbox.stub( mw.storage, 'session', mwStorageStub );

	mw.storage.session.set( 'mp-sessionTickTickCount', null );
	const streamName = 'testStream';
	const schemaID = 'testSchema';
	SessionLengthInstrumentMixin.start( streamName, schemaID );
	assert.strictEqual( Number( mw.storage.session.get( 'mp-sessionTickTickCount' ) ), 1,
		'First tick should set count to 1'
	);
} );

QUnit.test( 'Start sessionLength Tracking', ( assert ) => {
	const streamName = 'testStream';
	const schemaID = 'testSchema';
	const data = {};
	const expected = { schemaID, data };
	mw.config.set( 'wgUserGroups', [ '*', 'user' ] );
	SessionLengthInstrumentMixin.start( streamName, schemaID );
	assert.strictEqual( SessionLengthInstrumentMixin.state.has( streamName ), true, `State should have '${ streamName }'` );
	assert.deepEqual( SessionLengthInstrumentMixin.state.get( streamName ), expected, `Schema ID for '${ streamName }' should be '${ expected }'` );
} );

QUnit.test( 'Start sessionLength with data', ( assert ) => {
	const streamName = 'testStream';
	const schemaID = 'testSchema';
	const data = {
		testData: true
	};
	const expected = { schemaID, data };
	SessionLengthInstrumentMixin.start( streamName, schemaID, data );
	assert.strictEqual( SessionLengthInstrumentMixin.state.has( streamName ), true, `State should have '${ streamName }'` );
	assert.deepEqual( SessionLengthInstrumentMixin.state.get( streamName ), expected, `Schema ID for '${ streamName }' should be '${ expected }'` );
} );

QUnit.test( 'Stop sessionLength Tracking', ( assert ) => {
	const streamName = 'testStream';
	SessionLengthInstrumentMixin.start( streamName, 'testSchema', {} );
	SessionLengthInstrumentMixin.stop( streamName );
	assert.strictEqual( SessionLengthInstrumentMixin.state.has( streamName ), false, `State should not have '${ streamName }'` );
} );

QUnit.test( 'Start sessionLength with Experiment instance and data', ( assert ) => {
	// Stub the `mw.testKitchen.Experiment` class. Only `submitInteraction` is required.
	function ExperimentStub() {
		this.submitInteraction = function () {};
	}
	const instrument = new ExperimentStub();
	const data = {
		testData: true
	};
	const expected = { instrument, data };
	SessionLengthInstrumentMixin.start( instrument, data );
	assert.strictEqual( SessionLengthInstrumentMixin.state.has( instrument ), true, 'State should have the given Experiment instance' );
	assert.deepEqual( SessionLengthInstrumentMixin.state.get( instrument ), expected, `State value for the given Experiment instance should be '${ expected }'` );
} );

QUnit.test( 'Start sessionLength with invalid object', ( assert ) => {
	const invalid = {
		I: 'M',
		invalid: true
	};
	assert.throws( () => SessionLengthInstrumentMixin.start( invalid ), 'Object passed to SessionLengthInstrumentMixin.start() is invalid' );
} );
