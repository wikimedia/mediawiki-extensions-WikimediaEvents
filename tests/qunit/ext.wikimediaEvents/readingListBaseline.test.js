/* eslint-env qunit */
'use strict';

const { trackBookmarkListIconButtonClicks, init,
	isOnCollectionPage
} = require( 'ext.wikimediaEvents/readingListBaseline.js' );

const OVERFLOW_LINK = `<li id="pt-readinglists-2" class="mw-list-item vector-menu-item--collapsible"><a href="#" class="cdx-button cdx-button--fake-button cdx-button--fake-button--enabled cdx-button--weight-quiet cdx-button--icon-only" id="" title="Your saved items"><span class="vector-icon mw-ui-icon-bookmarkList mw-ui-icon-wikimedia-bookmarkList cdx-button__icon"></span>
<span>Saved</span>
</a>
</li>`;
const USER_MENU_LINK = `<li id="pt-readinglists" class="mw-list-item vector-menu-item--collapsible"><a data-mw-interface="" href="#" title="Your saved items" class=""><span class="vector-icon mw-ui-icon-bookmarkList mw-ui-icon-wikimedia-bookmarkList cdx-button__icon"></span>
<span>Saved</span></a>
</li>`;
const STICKY_LINK = `<li id="pt-readinglists-sticky-header" class="mw-list-item"><a data-mw-interface="" href="/wiki/Special:ReadingLists/Jon_(WMF)" title="Your saved items" class=""><span class="vector-icon mw-ui-icon-bookmarkList mw-ui-icon-wikimedia-bookmarkList cdx-button__icon"></span>
<span>Saved</span></a>
</li>`;
let sandbox;

QUnit.module( 'ext.wikimediaEvents/readingListBaseline', ( hooks ) => {
	hooks.beforeEach( function () {
		sandbox = this.sandbox;
		mw.config.set( {
			wgArticlePath: '/wiki/$1',
			wgCanonicalSpecialPageName: 'ReadingLists'
		} );
		const fixture = document.getElementById( 'qunit-fixture' );
		const node = document.createElement( 'div' );
		node.innerHTML = `<ul>${ OVERFLOW_LINK }${ USER_MENU_LINK }${ STICKY_LINK }</ul>`;
		fixture.appendChild( node );
	} );

	QUnit.test( 'trackBookmarkListIconButtonClicks', ( assert ) => {
		const instrument = {
			send: sandbox.spy()
		};
		trackBookmarkListIconButtonClicks( instrument );
		document.querySelector( '#pt-readinglists-2' ).dispatchEvent( new Event( 'click' ) );
		assert.strictEqual( instrument.send.callCount, 1, 'instrument was called' );
		document.querySelector( '#pt-readinglists-2 .vector-icon' ).dispatchEvent( new Event( 'click' ) );
		assert.strictEqual( instrument.send.callCount, 2, 'instrument was called' );
		document.querySelector( '#pt-readinglists-2 a' ).dispatchEvent( new Event( 'click' ) );
		assert.strictEqual( instrument.send.callCount, 3, 'instrument was called' );
	} );

	QUnit.test( 'init', ( assert ) => {
		const instrument = {
			send: sandbox.spy()
		};
		init( instrument );
		document.querySelector( '#pt-readinglists-2 a' ).dispatchEvent( new Event( 'click' ) );
		assert.strictEqual( instrument.send.callCount, 1, 'instrument was called' );

		document.querySelector( '#pt-readinglists-sticky-header a' ).dispatchEvent( new Event( 'click' ) );
		assert.strictEqual( instrument.send.callCount, 2, 'instrument was called for sticky header' );
	} );

	QUnit.test( 'returns false for the reading list feed (no username)', ( assert ) => {
		assert.strictEqual( isOnCollectionPage( '/wiki/Special:ReadingLists' ), false );
	} );

	QUnit.test( 'returns false for the reading list feed with a trailing slash', ( assert ) => {
		assert.strictEqual( isOnCollectionPage( '/wiki/Special:ReadingLists/' ), false );
	} );

	QUnit.test( 'returns false for Special:ReadingLists/<username>', ( assert ) => {
		assert.strictEqual( isOnCollectionPage( '/wiki/Special:ReadingLists/SomeUser' ), false );
	} );

	QUnit.test( 'returns true for Special:ReadingLists/<username>/<id>', ( assert ) => {
		assert.strictEqual( isOnCollectionPage( '/wiki/Special:ReadingLists/SomeUser/123' ), true );
	} );

	QUnit.test( 'returns true for Special:ReadingLists/<username>/<id>/<name>', ( assert ) => {
		assert.strictEqual(
			isOnCollectionPage( '/wiki/Special:ReadingLists/SomeUser/123/My+List' ),
			true
		);
	} );

	QUnit.test( 'returns false for usernames with leading number<id>', ( assert ) => {
		assert.strictEqual( isOnCollectionPage( '/wiki/Special:ReadingLists/123User' ), false );
		assert.strictEqual( isOnCollectionPage( '/wiki/Special:ReadingLists/123User/80' ), true );
	} );

	QUnit.test( 'returns true for Special:ReadingLists/<id>', ( assert ) => {
		assert.strictEqual( isOnCollectionPage( '/wiki/Special:ReadingLists/123' ), true );
	} );

	QUnit.test( 'returns true for Special:ReadingLists/<id>/<name>', ( assert ) => {
		assert.strictEqual( isOnCollectionPage( '/wiki/Special:ReadingLists/123/My+List' ), true );
	} );

	QUnit.test( 'strips the configured article path prefix before parsing', ( assert ) => {
		mw.config.set( 'wgArticlePath', '/w/$1' );
		assert.strictEqual( isOnCollectionPage( '/w/Special:ReadingLists/123' ), true );
	} );

	QUnit.test( 'falls back to the full path when the article path prefix is absent', ( assert ) => {
		assert.strictEqual( isOnCollectionPage( 'Special:ReadingLists/123' ), true );
	} );
} );
