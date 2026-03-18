/* eslint-env qunit */
'use strict';

const setupExternalLinkInstrumentation = require( 'ext.wikimediaEvents/externalLinks.js' );

QUnit.module( 'ext.wikimediaEvents/externalLinks', ( hooks ) => {
	const config = require( 'ext.wikimediaEvents/config.json' );
	let original;
	let sendBeaconStub;
	let $content;

	hooks.beforeEach( function () {
		original = Object.assign( {}, config );
		config.WikimediaEventsExternalLinkInstrumentation = true;
		config.WikimediaEventsExternalLinkTrackedDomains = [ 'example.com', 'foo.test.org' ];
		config.WMEStatsBeaconUri = '/beacon/stats';

		mw.config.set( 'wgUserId', null );
		mw.config.set( 'wgDBname', 'enwiki' );

		sendBeaconStub = this.sandbox.stub( mw.eventLog, 'sendBeacon' );

		$content = $( '<div class="mw-parser-output"></div>' );
		$( '#qunit-fixture' ).append( $content );
	} );

	hooks.afterEach( () => {
		Object.assign( config, original );
		delete config.WikimediaEventsExternalLinkInstrumentation;
		delete config.WikimediaEventsExternalLinkTrackedDomains;
		delete config.WMEStatsBeaconUri;
	} );

	QUnit.test( 'sends beacon for tracked domain', ( assert ) => {
		$content.append( '<a class="external" href="https://example.com/page">Link</a>' );
		setupExternalLinkInstrumentation();

		$content.find( 'a.external' ).trigger( 'mousedown' );

		assert.strictEqual( sendBeaconStub.callCount, 1, 'beacon sent' );
		assert.strictEqual(
			sendBeaconStub.getCall( 0 ).args[ 0 ],
			'/beacon/stats?mediawiki_WikimediaEvents_extLinkClick_total:1|c%7C%23wiki:enwiki,domain:example_com',
			'beacon payload'
		);
	} );

	QUnit.test( 'sends beacon for subdomain of tracked domain', ( assert ) => {
		$content.append( '<a class="external" href="https://www.example.com/page">Link</a>' );
		setupExternalLinkInstrumentation();

		$content.find( 'a.external' ).trigger( 'mousedown' );

		assert.strictEqual( sendBeaconStub.callCount, 1, 'beacon sent' );
		assert.strictEqual(
			sendBeaconStub.getCall( 0 ).args[ 0 ],
			'/beacon/stats?mediawiki_WikimediaEvents_extLinkClick_total:1|c%7C%23wiki:enwiki,domain:example_com',
			'beacon payload includes subdomain'
		);
	} );

	QUnit.test( 'does not send beacon for untracked domain', ( assert ) => {
		$content.append( '<a class="external" href="https://untracked.com/page">Link</a>' );
		setupExternalLinkInstrumentation();

		$content.find( 'a.external' ).trigger( 'mousedown' );

		assert.strictEqual( sendBeaconStub.callCount, 0, 'no beacon sent' );
	} );

	QUnit.test( 'does not send beacon for partial domain match', ( assert ) => {
		// "notexample.com" should not match "example.com"
		$content.append( '<a class="external" href="https://notexample.com/page">Link</a>' );
		setupExternalLinkInstrumentation();

		$content.find( 'a.external' ).trigger( 'mousedown' );

		assert.strictEqual( sendBeaconStub.callCount, 0, 'no beacon for partial match' );
	} );

	QUnit.test( 'does not send beacon for logged-in user', ( assert ) => {
		mw.config.set( 'wgUserId', 12345 );
		$content.append( '<a class="external" href="https://example.com/page">Link</a>' );
		setupExternalLinkInstrumentation();

		$content.find( 'a.external' ).trigger( 'mousedown' );

		assert.strictEqual( sendBeaconStub.callCount, 0, 'no beacon for logged-in user' );
	} );

	QUnit.test( 'does not send beacon when instrumentation is disabled', ( assert ) => {
		config.WikimediaEventsExternalLinkInstrumentation = false;
		$content.append( '<a class="external" href="https://example.com/page">Link</a>' );
		setupExternalLinkInstrumentation();

		$content.find( 'a.external' ).trigger( 'mousedown' );

		assert.strictEqual( sendBeaconStub.callCount, 0, 'no beacon when disabled' );
	} );

	QUnit.test( 'does not send beacon when beacon URI is missing', ( assert ) => {
		delete config.WMEStatsBeaconUri;
		$content.append( '<a class="external" href="https://example.com/page">Link</a>' );
		setupExternalLinkInstrumentation();

		$content.find( 'a.external' ).trigger( 'mousedown' );

		assert.strictEqual( sendBeaconStub.callCount, 0, 'no beacon without URI' );
	} );

	QUnit.test( 'replaces dots with underscores in domain label', ( assert ) => {
		$content.append( '<a class="external" href="https://foo.test.org/page">Link</a>' );
		setupExternalLinkInstrumentation();

		$content.find( 'a.external' ).trigger( 'mousedown' );

		assert.strictEqual( sendBeaconStub.callCount, 1, 'beacon sent' );
		assert.true(
			sendBeaconStub.getCall( 0 ).args[ 0 ].includes( 'domain:foo_test_org' ),
			'dots replaced with underscores'
		);
	} );

	QUnit.test( 'handles multiple tracked links independently', ( assert ) => {
		$content.append(
			'<a class="external" href="https://example.com/a">A</a>' +
			'<a class="external" href="https://foo.test.org/b">B</a>' +
			'<a class="external" href="https://untracked.com/c">C</a>'
		);
		setupExternalLinkInstrumentation();

		$content.find( 'a.external' ).eq( 0 ).trigger( 'mousedown' );
		$content.find( 'a.external' ).eq( 1 ).trigger( 'mousedown' );
		$content.find( 'a.external' ).eq( 2 ).trigger( 'mousedown' );

		assert.strictEqual( sendBeaconStub.callCount, 2, 'only tracked domains fire beacons' );
	} );

	QUnit.test( 'ignores links outside .mw-parser-output', ( assert ) => {
		$( '#qunit-fixture' ).append(
			'<a class="external" href="https://example.com/outside">Outside</a>'
		);
		setupExternalLinkInstrumentation();

		$( '#qunit-fixture > a.external' ).trigger( 'mousedown' );

		assert.strictEqual( sendBeaconStub.callCount, 0, 'no beacon for links outside content' );
	} );
} );
