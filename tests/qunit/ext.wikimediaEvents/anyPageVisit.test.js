/* eslint-env qunit */
'use strict';

const anyPageVisit = require( 'ext.wikimediaEvents/anyPageVisit.js' );

QUnit.module( 'ext.wikimediaEvents/anyPageVisit', () => {
	const {
		classifyReferrer,
		REFERRER_CLASS_NONE,
		REFERRER_CLASS_INTERNAL,
		REFERRER_CLASS_EXTERNAL
	} = anyPageVisit;

	QUnit.test( 'classifyReferrer: empty referrer is no_referrer', ( assert ) => {
		assert.strictEqual( classifyReferrer( '' ), REFERRER_CLASS_NONE );
		assert.strictEqual( classifyReferrer( null ), REFERRER_CLASS_NONE );
		assert.strictEqual( classifyReferrer( undefined ), REFERRER_CLASS_NONE );
	} );

	QUnit.test( 'classifyReferrer: Wikimedia hosts are internal', ( assert ) => {
		const internal = [
			'https://en.wikipedia.org/wiki/Foo',
			'https://en.m.wikipedia.org/wiki/Foo',
			'https://commons.wikimedia.org/wiki/File:Bar.jpg',
			'https://www.wikidata.org/wiki/Q42',
			'https://de.wiktionary.org/wiki/Haus',
			'https://www.wikifunctions.org/',
			'https://wikiba.se/',
			'https://mediawiki.org/wiki/API'
		];
		internal.forEach( ( referrer ) => {
			assert.strictEqual(
				classifyReferrer( referrer ),
				REFERRER_CLASS_INTERNAL,
				referrer
			);
		} );
	} );

	QUnit.test( 'classifyReferrer: non-Wikimedia hosts are external', ( assert ) => {
		const external = [
			'https://www.google.com/search?q=foo',
			'https://t.co/abc',
			'https://example.org/page',
			// Look-alike host that merely contains a Wikimedia domain string.
			'https://wikipedia.org.evil.example/'
		];
		external.forEach( ( referrer ) => {
			assert.strictEqual(
				classifyReferrer( referrer ),
				REFERRER_CLASS_EXTERNAL,
				referrer
			);
		} );
	} );

	QUnit.test( 'classifyReferrer: unparseable non-empty referrer is external', ( assert ) => {
		assert.strictEqual( classifyReferrer( 'not a url' ), REFERRER_CLASS_EXTERNAL );
	} );

	QUnit.test( 'factory does not record referrer class by default', function ( assert ) {
		const send = this.sandbox.spy();
		anyPageVisit()( { send } );

		assert.strictEqual( send.callCount, 1, 'send called once' );
		const [ action, interactionData ] = send.getCall( 0 ).args;
		assert.strictEqual( action, 'page_visit', 'page_visit action' );
		assert.deepEqual( interactionData, {}, 'no action_source recorded by default' );
	} );

	QUnit.test( 'factory records referrer class when requested', function ( assert ) {
		const send = this.sandbox.spy();
		anyPageVisit( { recordReferrerClass: true } )( { send } );

		assert.strictEqual( send.callCount, 1, 'send called once' );
		const interactionData = send.getCall( 0 ).args[ 1 ];
		assert.true(
			[
				REFERRER_CLASS_NONE,
				REFERRER_CLASS_INTERNAL,
				REFERRER_CLASS_EXTERNAL
			].includes( interactionData.action_source ),
			'action_source is a valid referrer class'
		);
	} );

	QUnit.test( 'factory sends required contextual attributes', function ( assert ) {
		const send = this.sandbox.spy();
		anyPageVisit()( { send } );

		const attributes = send.getCall( 0 ).args[ 2 ];
		assert.true(
			attributes.includes( 'page_namespace_id' ),
			'page_namespace_id contextual attribute included'
		);
	} );
} );
