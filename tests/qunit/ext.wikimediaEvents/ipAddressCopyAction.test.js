/* eslint-env qunit */
'use strict';

var instrument = require( '../../../modules/ext.wikimediaEvents/ipAddressCopyAction.js' );

QUnit.module( 'ext.wikimediaEvents/ipAddressCopyAction' );

QUnit.test( 'isEnabled', function ( assert ) {
	mw.config.set( 'wgAction', 'history' );

	assert.ok( instrument.isEnabled(), 'The instrument is enabled when viewing the history of a page' );

	// ---

	mw.config.set( 'wgAction', 'view' );

	assert.notOk( instrument.isEnabled(), 'The instrument is not enabled when viewing a page' );

	// ---

	[
		'Recentchanges',
		'Log',
		'Investigate',
		'Contributions'
	].forEach( function ( canonicalSpecialPageName ) {
		mw.config.set( 'wgCanonicalSpecialPageName', canonicalSpecialPageName );

		assert.ok( instrument.isEnabled(), 'The instrument is enabled on Special:' + canonicalSpecialPageName );
	} );

	// ---

	mw.config.set( 'wgCanonicalSpecialPageName', 'Version' );

	assert.notOk( instrument.isEnabled(), 'The instrument is not enabled for other special pages' );
} );

QUnit.test( 'log', function ( assert ) {
	this.sandbox.spy( mw, 'track' );

	mw.config.set( 'wgAction', 'view' );
	mw.config.set( 'wgCanonicalSpecialPageName', 'Log' );

	instrument.log();

	assert.strictEqual( mw.track.lastCall.args[ 0 ], 'counter.IPInfo.ip-address-copy.special-log' );

	// ---

	mw.config.set( 'wgAction', 'history' );

	instrument.log();

	assert.strictEqual( mw.track.lastCall.args[ 0 ], 'counter.IPInfo.ip-address-copy.action-history' );
} );
