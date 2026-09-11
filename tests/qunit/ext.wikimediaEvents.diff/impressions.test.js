/* eslint-env qunit */
'use strict';

QUnit.module( 'ext.wikimediaEvents.diff/impressions', ( hooks ) => {
	const impressions = require( 'ext.wikimediaEvents.diff/impressions.js' );

	// #qunit-fixture is deliberately positioned off-screen, so nothing inside it ever
	// intersects the viewport. These tests exercise a real IntersectionObserver, so they
	// need somewhere genuinely on screen instead.
	let $viewport;

	hooks.beforeEach( () => {
		impressions.reset();
		$viewport = $( '<div>' )
			.css( {
				position: 'fixed',
				top: 0,
				left: 0,
				width: '80px',
				height: '80px'
			} )
			.appendTo( document.body );
	} );

	hooks.afterEach( () => {
		impressions.reset();
		$viewport.remove();
	} );

	/**
	 * @return {{events: Array<Object>, send: Function}}
	 */
	function newFakeSender() {
		const sender = {
			events: [],
			send: ( action, interactionData ) => {
				sender.events.push( Object.assign( { action: action }, interactionData ) );
			}
		};

		return sender;
	}

	/**
	 * IntersectionObserver reports asynchronously, so give it a frame to do so. Two, to
	 * cover the layout that precedes the first report.
	 *
	 * @return {Promise}
	 */
	function afterObserverRuns() {
		return new Promise( ( resolve ) => {
			requestAnimationFrame( () => requestAnimationFrame( () => setTimeout( resolve, 0 ) ) );
		} );
	}

	/**
	 * @param {boolean} visible
	 * @return {Element}
	 */
	function appendElement( visible ) {
		return $( '<div>' )
			.text( 'x' )
			.css( visible ? {} : { display: 'none' } )
			.appendTo( $viewport )[ 0 ];
	}

	QUnit.test( 'a visible element sends one impression', async ( assert ) => {
		const sender = newFakeSender();

		impressions.observe( appendElement( true ), 'Undo', sender );
		await afterObserverRuns();

		assert.deepEqual( sender.events, [ { action: 'impression' } ] );
	} );

	QUnit.test( 'a hidden element sends nothing', async ( assert ) => {
		// This is the whole reason this module exists: DifferenceEngine renders both the
		// desktop and the mobile copy of undo, Thanks, rollback and the username, and only
		// one of them is ever shown.
		const sender = newFakeSender();

		impressions.observe( appendElement( false ), 'Undo', sender );
		await afterObserverRuns();

		assert.deepEqual( sender.events, [] );
	} );

	QUnit.test( 'one impression per affordance, not per element', async ( assert ) => {
		// "Edit" is a page tab, a VisualEditor tab and a link beside each revision, but a
		// pageview offers one opportunity to click Edit.
		const sender = newFakeSender();

		impressions.observe( appendElement( true ), 'Edit', sender );
		impressions.observe( appendElement( true ), 'Edit', sender );
		impressions.observe( appendElement( true ), 'Edit', sender );
		await afterObserverRuns();

		assert.strictEqual( sender.events.length, 1 );
	} );

	QUnit.test( 'a hidden copy does not suppress a visible one', async ( assert ) => {
		const sender = newFakeSender();

		impressions.observe( appendElement( false ), 'Undo', sender );
		impressions.observe( appendElement( true ), 'Undo', sender );
		await afterObserverRuns();

		assert.strictEqual( sender.events.length, 1 );
	} );

	QUnit.test( 'one element can carry two affordances', async ( assert ) => {
		// The visual/wikitext switcher is one widget offering two of them.
		const sender = newFakeSender();
		const element = appendElement( true );

		impressions.observe( element, 'Visual mode', sender );
		impressions.observe( element, 'Wikitext mode', sender );
		await afterObserverRuns();

		assert.strictEqual( sender.events.length, 2 );
	} );

	QUnit.test( 'different affordances each send once', async ( assert ) => {
		const sender = newFakeSender();

		impressions.observe( appendElement( true ), 'Undo', sender );
		impressions.observe( appendElement( true ), 'Thank', sender );
		await afterObserverRuns();

		assert.strictEqual( sender.events.length, 2 );
	} );

	QUnit.test( 'stop() allows a fresh impression for the next diff', async ( assert ) => {
		const sender = newFakeSender();

		impressions.observe( appendElement( true ), 'Undo', sender );
		await afterObserverRuns();
		assert.strictEqual( sender.events.length, 1 );

		// A RevisionSlider drag replaces the diff, which is a fresh opportunity to act.
		impressions.stop();
		impressions.observe( appendElement( true ), 'Undo', sender );
		await afterObserverRuns();

		assert.strictEqual( sender.events.length, 2 );
	} );
} );
