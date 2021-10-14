/* eslint-env qunit */
'use strict';

var instrument = require( '../../../modules/ext.wikimediaEvents/ipAddressCopyAction.js' );

QUnit.module( 'ext.wikimediaEvents/ipAddressCopyAction', QUnit.newMwEnvironment() );

QUnit.test( 'isEnabled', function ( assert ) {
	mw.config.set( 'wgAction', 'history' );
	assert.true( instrument.isEnabled(), 'viewing the history of a page' );

	mw.config.set( 'wgAction', 'view' );
	assert.false( instrument.isEnabled(), 'reading a page' );

	[
		'Recentchanges',
		'Log',
		'Investigate',
		'Contributions'
	].forEach( function ( specialPageName ) {
		mw.config.set( 'wgCanonicalSpecialPageName', specialPageName );
		assert.true( instrument.isEnabled(), 'on Special:' + specialPageName );
	} );

	mw.config.set( 'wgCanonicalSpecialPageName', 'Version' );
	assert.false( instrument.isEnabled(), 'on Special:Version' );
} );

QUnit.test( 'log', function ( assert ) {
	this.sandbox.spy( mw, 'track' );

	mw.config.set( 'wgDBname', 'wikidb' );
	mw.config.set( 'wgAction', 'view' );
	mw.config.set( 'wgCanonicalSpecialPageName', 'Log' );

	instrument.log();

	assert.strictEqual( mw.track.getCall( 0 ).args[ 0 ], 'counter.MediaWiki.ipinfo_address_copy.special_log' );
	assert.strictEqual( mw.track.getCall( 1 ).args[ 0 ], 'counter.MediaWiki.ipinfo_address_copy_by_wiki.wikidb.special_log' );

	mw.config.set( 'wgAction', 'history' );

	instrument.log();

	assert.strictEqual( mw.track.getCall( 2 ).args[ 0 ], 'counter.MediaWiki.ipinfo_address_copy.action_history' );
	assert.strictEqual( mw.track.getCall( 3 ).args[ 0 ], 'counter.MediaWiki.ipinfo_address_copy_by_wiki.wikidb.action_history' );
} );
