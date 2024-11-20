/* eslint-env qunit */
'use strict';

QUnit.module( 'ext.wikimediaEvents/stats', ( hooks ) => {
	const config = require( 'ext.wikimediaEvents/config.json' );
	let original;
	let stub;
	hooks.beforeEach( function () {
		original = config.statsdBaseUri;
		config.statsdBaseUri = '/beacon/statsv';

		this.sandbox.useFakeTimers();
		this.sandbox.stub( mw.eventLog, 'enqueue', ( fn ) => {
			setTimeout( fn, 1 );
		} );

		stub = this.sandbox.stub( navigator, 'sendBeacon' );
	} );
	hooks.afterEach( () => {
		config.statsdBaseUri = original;
	} );

	QUnit.test( 'counter [single]', function ( assert ) {
		mw.track( 'counter.foo.quux', 5 );

		assert.strictEqual( stub.callCount, 0, 'buffer awaits flush' );

		this.sandbox.clock.tick( 1 );
		assert.strictEqual( stub.callCount, 1, 'flushed' );
		assert.propEqual( stub.getCall( 0 ).args, [
			'/beacon/statsv?foo.quux=5c'
		] );
	} );

	QUnit.test( 'counter [multiple]', function ( assert ) {
		mw.track( 'counter.foo.quux', 5 );
		mw.track( 'counter.foo.quux', 2 );
		mw.track( 'counter.bar', 3 );

		assert.strictEqual( stub.callCount, 0, 'buffer awaits flush' );

		this.sandbox.clock.tick( 1 );
		assert.strictEqual( stub.callCount, 1, 'flushed' );
		assert.propEqual( stub.getCall( 0 ).args, [
			'/beacon/statsv?foo.quux=5c&foo.quux=2c&bar=3c'
		] );
	} );

	QUnit.test( 'counter [batch size]', function ( assert ) {
		const LONG = 'x'.repeat( 3000 );

		mw.track( 'counter.foo.quux', 5 );
		assert.strictEqual( stub.callCount, 0, 'buffer awaits flush' );

		mw.track( `counter.foo.${ LONG }`, 2 );
		assert.strictEqual( stub.callCount, 0, 'buffer awaits flush' );

		mw.track( `counter.bar.${ LONG }`, 3 );
		assert.strictEqual( stub.callCount, 1, 'flush early if buffer would be too large' );
		assert.propEqual( stub.getCall( 0 ).args, [
			`/beacon/statsv?foo.quux=5c&foo.${ LONG }=2c`
		] );

		this.sandbox.clock.tick( 1 );
		assert.strictEqual( stub.callCount, 2, 'scheduled flush' );
		assert.propEqual( stub.getCall( 1 ).args, [
			`/beacon/statsv?bar.${ LONG }=3c`
		] );
	} );

	QUnit.test( 'timing', function ( assert ) {
		mw.track( 'timing.bar', 1234.56 );
		this.sandbox.clock.tick( 1 );
		assert.strictEqual( stub.callCount, 1, 'scheduled flush' );
		assert.propEqual( stub.getCall( 0 ).args, [
			'/beacon/statsv?bar=1235ms'
		] );
	} );

	QUnit.test( 'gauge', function ( assert ) {
		mw.track( 'gauge.bar', 42 );
		this.sandbox.clock.tick( 1 );
		assert.strictEqual( stub.callCount, 1, 'scheduled flush' );
		assert.propEqual( stub.getCall( 0 ).args, [
			'/beacon/statsv?bar=42g'
		] );
	} );
} );
