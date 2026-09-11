/* eslint-env qunit */
'use strict';

QUnit.module( 'ext.wikimediaEvents.diff/context', ( hooks ) => {
	const context = require( 'ext.wikimediaEvents.diff/context.js' );
	const diffMode = require( 'ext.wikimediaEvents.diff/diffMode.js' );
	// The server makes this file from the PersonalDashboardFeedSources attribute, so it is
	// empty if Personal Dashboard is not installed. Set it here, because these tests must
	// give the same result on all wikis. Write to the same object, because the module holds
	// a reference to it.
	const feedSources = require( 'ext.wikimediaEvents.diff/feedSources.json' );
	let originalFeedSources;

	hooks.beforeEach( function () {
		originalFeedSources = Object.assign( {}, feedSources );
		feedSources.sources = [
			'recentchanges',
			'watchlist',
			'recentlyedited',
			'mostedited'
		];
		context.reset();
		diffMode.reset();
		mw.user.options.set( 'visualeditor-diffmode-historical', 'source' );
		mw.config.set( 'wgDiffNewId', 1234567890 );
		this.getParamValue = this.sandbox.stub( mw.util, 'getParamValue' ).returns( null );
	} );

	hooks.afterEach( () => {
		Object.assign( feedSources, originalFeedSources );
		context.reset();
		diffMode.reset();
	} );

	QUnit.test( 'records the diff mode and the new revision id', ( assert ) => {
		const actionContext = JSON.parse( context.newActionContext() );

		assert.strictEqual( actionContext.diff_mode, diffMode.MODE_WIKITEXT_TABLE );
		assert.strictEqual( actionContext.rev_id, 1234567890 );
		assert.deepEqual( Object.keys( actionContext ).sort(), [ 'diff_mode', 'rev_id' ] );
	} );

	QUnit.test( 'a fake diff has no revision id', ( assert ) => {
		// DifferenceEngine sets wgDiffNewId to false for e.g. the diff of a page creation.
		mw.config.set( 'wgDiffNewId', false );

		assert.strictEqual( JSON.parse( context.newActionContext() ).rev_id, null );
	} );

	QUnit.test( 'follows the diff mode as it changes', ( assert ) => {
		diffMode.setVisual( true );

		assert.strictEqual(
			JSON.parse( context.newActionContext() ).diff_mode,
			diffMode.MODE_VISUAL
		);
	} );

	QUnit.test( 'records a recognised origin', function ( assert ) {
		this.getParamValue.withArgs( context.ORIGIN_PARAM )
			.returns( 'personaldashboard-watchlist' );

		assert.strictEqual(
			JSON.parse( context.newActionContext() ).origin,
			'personaldashboard-watchlist'
		);
	} );

	QUnit.test( 'omits an absent origin', ( assert ) => {
		assert.false(
			'origin' in JSON.parse( context.newActionContext() ),
			'no origin key at all, rather than a null one'
		);
	} );

	QUnit.test( 'getOrigin accepts every registered feed source', function ( assert ) {
		context.getOriginValues().forEach( ( value ) => {
			this.getParamValue.withArgs( context.ORIGIN_PARAM ).returns( value );

			assert.strictEqual( context.getOrigin(), value, value );
		} );
	} );

	QUnit.test( 'getOrigin drops anything unrecognised', function ( assert ) {
		// A query parameter is reader-controlled, so it must never be logged unvalidated.
		const rejected = [
			null,
			'',
			'personaldashboard',
			'PERSONALDASHBOARD-WATCHLIST',
			'personaldashboard-somethingelse',
			'<script>alert(1)</script>'
		];

		rejected.forEach( ( value ) => {
			this.getParamValue.withArgs( context.ORIGIN_PARAM ).returns( value );

			assert.strictEqual( context.getOrigin(), null, String( value ) );
		} );
	} );

	QUnit.test( 'the recognised values come from the registered feed sources', ( assert ) => {
		// A made-up name, to show the value is read from the server and is not written here.
		feedSources.sources = [ 'examplefeed' ];
		context.reset();

		assert.deepEqual( context.getOriginValues(), [ 'personaldashboard-examplefeed' ] );
	} );

	QUnit.test( 'no registered feed source means no origin', function ( assert ) {
		// Personal Dashboard is not installed. The instrument must recognise no origin, and
		// it must not fail.
		feedSources.sources = [];
		context.reset();
		this.getParamValue.withArgs( context.ORIGIN_PARAM )
			.returns( 'personaldashboard-watchlist' );

		assert.strictEqual( context.getOrigin(), null );
		assert.false( 'origin' in JSON.parse( context.newActionContext() ) );
	} );

	QUnit.test( 'the origin is read once per page', function ( assert ) {
		this.getParamValue.withArgs( context.ORIGIN_PARAM )
			.returns( 'personaldashboard-recentchanges' );

		context.newActionContext();
		context.newActionContext();

		assert.strictEqual(
			this.getParamValue.withArgs( context.ORIGIN_PARAM ).callCount,
			1
		);
	} );
} );
