/* eslint-env qunit */
'use strict';

QUnit.module( 'ext.wikimediaEvents/statsd', ( hooks ) => {
	const config = require( 'ext.wikimediaEvents/config.json' );
	let original;
	let stub;
	hooks.beforeEach( function ( assert ) {
		original = Object.assign( {}, config );
		config.WMEStatsdBaseUri = '/beacon/statsv';
		config.WMEStatsBeaconUri = '/beacon/stats';

		this.sandbox.useFakeTimers();
		this.sandbox.stub( mw.eventLog, 'enqueue', ( fn ) => {
			setTimeout( fn, 1 );
		} );

		this.sandbox.stub( mw.errorLogger, 'logError' );
		this.sandbox.stub( mw.log, 'error', ( err ) => {
			assert.step( err.message );
		} );
		this.sandbox.stub( mw.log, 'warn', ( message ) => {
			assert.step( 'warn: ' + message );
		} );

		stub = this.sandbox.stub( navigator, 'sendBeacon' );
	} );
	hooks.afterEach( () => {
		delete config.WMEStatsdBaseUri;
		delete config.WMEStatsBeaconUri;
		Object.assign( config, original );
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

	QUnit.test( 'stats [invalid name]', function ( assert ) {

		mw.track( 'stats.foo_bar', 5 );
		this.sandbox.clock.tick( 1 );

		assert.verifySteps( [
			'Invalid stat name foo_bar'
		] );
		assert.strictEqual( stub.callCount, 0, 'beacons' );
	} );

	QUnit.test.each( 'stats [invalid counter value]', [ null, 3.14, 0, -1 ], function ( assert, count ) {
		mw.track( 'stats.mediawiki_foo_bar_total', count );
		this.sandbox.clock.tick( 1 );

		assert.verifySteps( [
			'Invalid counter value for mediawiki_foo_bar_total'
		] );
		assert.strictEqual( stub.callCount, 0, 'beacons' );
	} );

	QUnit.test( 'stats [invalid label key]', function ( assert ) {
		mw.track( 'stats.mediawiki_foo_bar_total', 5, { 'Main Page': 'title' } );
		this.sandbox.clock.tick( 1 );

		assert.verifySteps( [
			'Invalid stat label "Main Page"'
		] );
		assert.strictEqual( stub.callCount, 0, 'beacons' );
	} );

	QUnit.test.each( 'stats [invalid label value]', {
		space: 'Main Page',
		colon: ':',
		empty: ''
	}, function ( assert, invalidValue ) {
		mw.track( 'stats.mediawiki_foo_bar_total', 5, { title: invalidValue } );
		this.sandbox.clock.tick( 1 );

		assert.verifySteps( [
			`warn: Invalid label value for mediawiki_foo_bar_total title "${ invalidValue }"`
		] );
		assert.strictEqual( stub.callCount, 1, 'beacons' );
		assert.propEqual( stub.getCall( 0 ).args, [
			'/beacon/stats?mediawiki_foo_bar_total:5|c%7C%23title:_invalid_value'
		], 'beacon' );
	} );

	QUnit.test( 'stats [counter]', function ( assert ) {
		mw.track( 'stats.mediawiki_foo_bar_total', 5, { kind: 'main', ding: 'dong' } );
		this.sandbox.clock.tick( 1 );

		assert.verifySteps( [], 'errors' );
		assert.strictEqual( stub.callCount, 1, 'beacons' );
		assert.propEqual( stub.getCall( 0 ).args, [
			'/beacon/stats?mediawiki_foo_bar_total:5|c%7C%23kind:main,ding:dong'
		], 'beacon' );
	} );

	QUnit.test( 'stats [multiple counters]', function ( assert ) {
		mw.track( 'stats.mediawiki_bar_total' );
		mw.track( 'stats.mediawiki_foo_bar_total', 5, { kind: 'main', ding: 'dong' } );
		mw.track( 'stats.mediawiki_example_thing_total', 42, { a: 'A', b: 'B' } );
		this.sandbox.clock.tick( 1 );

		assert.verifySteps( [], 'errors' );
		assert.strictEqual( stub.callCount, 1, 'beacons' );
		assert.propEqual( stub.getCall( 0 ).args, [
			'/beacon/stats?mediawiki_bar_total:1|c' +
				'%0Amediawiki_foo_bar_total:5|c%7C%23kind:main,ding:dong' +
				'%0Amediawiki_example_thing_total:42|c%7C%23a:A,b:B'
		], 'beacon' );
	} );

	QUnit.test( 'stats [batching]', function ( assert ) {
		const LONG = 'x'.repeat( 3000 );
		mw.track( 'stats.mediawiki_bar_total' );
		mw.track( 'stats.mediawiki_foo_bar_total', 5, { kind: 'main', ding: LONG } );
		mw.track( 'stats.mediawiki_example_thing_total', 42, { a: 'A', b: LONG } );
		this.sandbox.clock.tick( 1 );

		assert.verifySteps( [], 'errors' );
		assert.strictEqual( stub.callCount, 2, 'beacons' );
		assert.propEqual( stub.getCall( 0 ).args, [
			'/beacon/stats?mediawiki_bar_total:1|c' +
				`%0Amediawiki_foo_bar_total:5|c%7C%23kind:main,ding:${ LONG }`
		], 'beacon 1' );
		assert.propEqual( stub.getCall( 1 ).args, [
			`/beacon/stats?mediawiki_example_thing_total:42|c%7C%23a:A,b:${ LONG }`
		], 'beacon 2' );
	} );
} );
