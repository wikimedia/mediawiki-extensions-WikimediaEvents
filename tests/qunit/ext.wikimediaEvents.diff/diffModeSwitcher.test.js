/* eslint-env qunit */
'use strict';

QUnit.module( 'ext.wikimediaEvents.diff/diffModeSwitcher', ( hooks ) => {
	const diffMode = require( 'ext.wikimediaEvents.diff/diffMode.js' );
	const diffModeSwitcher = require( 'ext.wikimediaEvents.diff/diffModeSwitcher.js' );
	const impressions = require( 'ext.wikimediaEvents.diff/impressions.js' );

	hooks.beforeEach( () => {
		diffMode.reset();
		diffModeSwitcher.reset();
		impressions.reset();
		mw.user.options.set( 'visualeditor-diffmode-historical', 'source' );
	} );

	hooks.afterEach( () => {
		diffMode.reset();
		diffModeSwitcher.reset();
		impressions.reset();
	} );

	/**
	 * @return {{events: Array<Object>, send: Function}}
	 */
	function newFakeSender() {
		const sender = {
			events: [],
			send: ( action, interactionData ) => {
				sender.events.push( Object.assign(
					{
						action: action,
						// Captured at send time: index.js's real sender builds
						// action_context from this, and for a mode switch it must be the
						// mode the reader was leaving.
						modeWhenSent: diffMode.get()
					},
					interactionData
				) );
			}
		};

		return sender;
	}

	/**
	 * Both the server-rendered ButtonGroupWidget and the ButtonSelectWidget that
	 * VisualEditor swaps in give each button oo-ui-buttonElement and an oo-ui-icon-* icon.
	 *
	 * @param {Object} [options]
	 * @param {boolean} [options.visualSwitcher=true]
	 * @param {boolean} [options.inlineToggle=true]
	 * @return {jQuery}
	 */
	function renderSwitchers( { visualSwitcher = true, inlineToggle = true } = {} ) {
		const $prefix = $( '<div>' ).addClass( 'mw-diff-table-prefix' );

		if ( visualSwitcher ) {
			$prefix.append(
				$( '<div>' ).addClass( 've-init-mw-diffPage-diffMode' ).append(
					$( '<span>' ).addClass( 'oo-ui-buttonElement' ).append(
						$( '<span>' ).addClass( 'oo-ui-icon-eye' ),
						$( '<span>' ).text( 'Visual' )
					),
					$( '<span>' ).addClass( 'oo-ui-buttonElement' ).append(
						$( '<span>' ).addClass( 'oo-ui-icon-wikiText' ),
						$( '<span>' ).text( 'Wikitext' )
					)
				)
			);
		}

		if ( inlineToggle ) {
			$prefix.append(
				$( '<span>' ).attr( 'id', 'mw-diffPage-inline-toggle-switch' )
			);
		}

		return $prefix.appendTo( '#qunit-fixture' );
	}

	QUnit.test( 'clicking the switcher buttons', ( assert ) => {
		const sender = newFakeSender();
		const $prefix = renderSwitchers( { inlineToggle: false } );

		diffModeSwitcher.start( sender );

		const buttons = $prefix.find( '.oo-ui-buttonElement' ).toArray();

		// Click the icon rather than the button, as a reader clicking the icon would.
		buttons[ 0 ].querySelector( '.oo-ui-icon-eye' ).click();

		assert.strictEqual( sender.events.length, 1, 'one event' );
		assert.strictEqual( sender.events[ 0 ].action, 'click' );
		assert.strictEqual(
			sender.events[ 0 ].element_friendly_name,
			diffModeSwitcher.FRIENDLY_NAME_VISUAL
		);
		assert.strictEqual(
			sender.events[ 0 ].modeWhenSent,
			diffMode.MODE_WIKITEXT_TABLE,
			'records the mode being left, not the one being entered'
		);
		assert.strictEqual(
			diffMode.get(),
			diffMode.MODE_VISUAL,
			'later events see the new mode'
		);

		buttons[ 1 ].click();

		assert.strictEqual( sender.events[ 1 ].action, 'click' );
		assert.strictEqual(
			sender.events[ 1 ].element_friendly_name,
			diffModeSwitcher.FRIENDLY_NAME_WIKITEXT
		);
		assert.strictEqual(
			sender.events[ 1 ].modeWhenSent,
			diffMode.MODE_VISUAL,
			'switching back records the visual mode being left'
		);
		assert.strictEqual( diffMode.get(), diffMode.MODE_WIKITEXT_TABLE );

		assert.true(
			!!sender.events[ 0 ].funnel_entry_token,
			'events carry a funnel token'
		);
		assert.notStrictEqual(
			sender.events[ 0 ].funnel_entry_token,
			sender.events[ 1 ].funnel_entry_token,
			'each friendly name gets its own funnel token'
		);
	} );

	QUnit.test( 'clicking outside the switcher is ignored', ( assert ) => {
		const sender = newFakeSender();
		renderSwitchers( { inlineToggle: false } );

		diffModeSwitcher.start( sender );

		$( '<a>' ).appendTo( '#qunit-fixture' )[ 0 ].click();

		assert.deepEqual( sender.events, [] );
	} );

	QUnit.test( 'toggling inline on and off', ( assert ) => {
		const sender = newFakeSender();
		renderSwitchers( { visualSwitcher: false } );

		diffModeSwitcher.start( sender );

		// The toggle reports the state it has been switched to, so the friendly name is
		// resolved per event rather than fixed at registration.
		mw.hook( 'wikipage.diff.diffTypeSwitch' ).fire( {
			getValue: () => false,
			on: ( event, listener ) => {
				listener( true );
				listener( false );
			}
		} );

		assert.deepEqual(
			sender.events.map( ( event ) => event.element_friendly_name ),
			[
				diffModeSwitcher.FRIENDLY_NAME_INLINE_ON,
				diffModeSwitcher.FRIENDLY_NAME_INLINE_OFF
			]
		);
		assert.true( sender.events.every( ( event ) => event.action === 'click' ) );
		assert.deepEqual(
			sender.events.map( ( event ) => event.modeWhenSent ),
			[ diffMode.MODE_WIKITEXT_TABLE, diffMode.MODE_WIKITEXT_INLINE ],
			'each toggle records the mode it was switched away from'
		);
	} );

	QUnit.test( 'a new diff refreshes the funnel tokens', function ( assert ) {
		// RevisionSlider loads a new diff without a page load, so start() runs again. The
		// click listener is wired only on the first call, so it must read the current
		// sender and tokens rather than the ones the first call was given.
		const observe = this.sandbox.stub( impressions, 'observe' );
		const $prefix = renderSwitchers( { inlineToggle: false } );

		/**
		 * @param {Object} sender
		 * @return {Array<Object>} The sender's events, impression first
		 */
		function registerAndUse( sender ) {
			observe.resetHistory();
			diffModeSwitcher.start( sender );

			// Stand in for impressions.js reporting the switcher as visible. The fixture is
			// positioned off-screen, so a real IntersectionObserver never would.
			observe.getCalls()
				.filter( ( call ) => call.args[ 1 ] === diffModeSwitcher.FRIENDLY_NAME_VISUAL )
				.forEach( ( call ) => call.args[ 2 ].send( 'impression', {} ) );

			$prefix.find( '.oo-ui-icon-eye' )[ 0 ].click();

			return sender.events;
		}

		const first = registerAndUse( newFakeSender() );

		assert.strictEqual( first.length, 2, 'an impression and a click' );
		assert.strictEqual(
			first[ 1 ].funnel_entry_token,
			first[ 0 ].funnel_entry_token,
			'the click joins the impression it followed'
		);

		const second = registerAndUse( newFakeSender() );

		assert.strictEqual( second.length, 2, 'both events go to the current sender' );
		assert.strictEqual(
			second[ 1 ].funnel_entry_token,
			second[ 0 ].funnel_entry_token,
			'the new diff\'s click joins the new diff\'s impression'
		);
		assert.notStrictEqual(
			second[ 0 ].funnel_entry_token,
			first[ 0 ].funnel_entry_token,
			'the new diff gets a token of its own'
		);
		assert.strictEqual( first.length, 2, 'the superseded sender receives nothing more' );
	} );

	QUnit.test( 'neither switcher rendered, nothing to do', ( assert ) => {
		const sender = newFakeSender();

		diffModeSwitcher.start( sender );

		assert.deepEqual( sender.events, [] );
	} );
} );
