/* eslint-env qunit */
'use strict';

QUnit.module( 'ext.wikimediaEvents.diff/diffMode', ( hooks ) => {
	const diffMode = require( 'ext.wikimediaEvents.diff/diffMode.js' );

	hooks.beforeEach( () => {
		diffMode.reset();
		mw.user.options.set( 'visualeditor-diffmode-historical', 'source' );
	} );

	hooks.afterEach( () => {
		diffMode.reset();
	} );

	/**
	 * @param {string} diffType Either 'table' or 'inline'
	 */
	function renderDiff( diffType ) {
		$( '#qunit-fixture' ).append(
			$( '<table>' )
				// The following classes are used here:
				// * diff-type-table
				// * diff-type-inline
				.addClass( 'diff diff-type-' + diffType )
				.attr( 'data-mw-interface', '' )
		);
	}

	QUnit.test( 'two-column wikitext diff', ( assert ) => {
		renderDiff( 'table' );

		assert.strictEqual( diffMode.get(), diffMode.MODE_WIKITEXT_TABLE );
		assert.false( diffMode.isInline() );
	} );

	QUnit.test( 'inline wikitext diff', ( assert ) => {
		renderDiff( 'inline' );

		assert.strictEqual( diffMode.get(), diffMode.MODE_WIKITEXT_INLINE );
		assert.true( diffMode.isInline() );
	} );

	QUnit.test( 'no diff table falls back to two-column', ( assert ) => {
		assert.strictEqual( diffMode.get(), diffMode.MODE_WIKITEXT_TABLE );
	} );

	QUnit.test( 'visual mode wins over the wikitext axis', ( assert ) => {
		renderDiff( 'inline' );

		diffMode.setVisual( true );
		assert.strictEqual( diffMode.get(), diffMode.MODE_VISUAL );

		// Switching back reveals the wikitext axis unchanged underneath.
		diffMode.setVisual( false );
		assert.strictEqual( diffMode.get(), diffMode.MODE_WIKITEXT_INLINE );
	} );

	QUnit.test( 'initial visual mode comes from the user option', ( assert ) => {
		renderDiff( 'table' );
		mw.user.options.set( 'visualeditor-diffmode-historical', 'visual' );

		assert.strictEqual( diffMode.get(), diffMode.MODE_VISUAL );
	} );

	QUnit.test( 'the inline toggle updates the mode and notifies listeners', ( assert ) => {
		renderDiff( 'table' );

		const seen = [];
		diffMode.onInlineChange( ( value ) => seen.push( value ) );

		// Stands in for the OOUI ToggleSwitchWidget that mediawiki.diff hands over.
		const listeners = [];
		const widget = {
			getValue: () => false,
			on: ( event, listener ) => listeners.push( listener )
		};
		mw.hook( 'wikipage.diff.diffTypeSwitch' ).fire( widget );

		assert.strictEqual( listeners.length, 1, 'subscribed to the widget once' );
		assert.strictEqual( diffMode.get(), diffMode.MODE_WIKITEXT_TABLE );

		listeners[ 0 ]( true );
		assert.strictEqual( diffMode.get(), diffMode.MODE_WIKITEXT_INLINE );

		listeners[ 0 ]( false );
		assert.strictEqual( diffMode.get(), diffMode.MODE_WIKITEXT_TABLE );

		assert.deepEqual( seen, [ true, false ], 'listener saw each switch' );
	} );
} );
