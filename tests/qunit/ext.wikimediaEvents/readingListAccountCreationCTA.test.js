'use strict';

const readingListAccountCreationCTA = require( 'ext.wikimediaEvents/readingListAccountCreationCTA.js' );

QUnit.module( 'ext.wikimediaEvents/readingListAccountCreationCTA', QUnit.newMwEnvironment( {
	beforeEach() {
		// add all elements here so they will be reset between tests
		const fixture = document.querySelector( '#qunit-fixture' );

		// create fake watchstar and place it on the DOM
		this.watchstar = document.createElement( 'div' );
		this.watchstar.id = 'ca-watch';

		fixture.appendChild( this.watchstar );

		// ditto for login and signup buttons
		const drawer = document.createElement( 'div' );
		const anchors = document.createElement( 'div' );
		drawer.className = 'drawer';
		anchors.className = 'cta-drawer__anchors';

		this.login = document.createElement( 'a' );
		this.signup = document.createElement( 'a' );
		this.login.type = 'button';

		fixture.appendChild( drawer );
		drawer.appendChild( this.login );
		drawer.appendChild( anchors );
		anchors.appendChild( this.signup );
	}
} ) );

QUnit.test( 'track clicks to watchstar', function ( assert ) {
	const experiment = this.sandbox.spy( { send: () => {} } );

	readingListAccountCreationCTA.test.setupControlInstrumentation( experiment );

	// no events sent before click
	assert.true( experiment.send.notCalled );

	this.watchstar.click();

	// eslint-disable-next-line camelcase
	assert.true( experiment.send.calledOnceWith( 'click', { action_subtype: 'save_article_to_watchlist' } ) );
} );

QUnit.test( 'fire init event for drawer opening and track clicks to login and signup', function ( assert ) {
	const experiment = this.sandbox.spy( { send: () => {} } );

	readingListAccountCreationCTA.test.setupControlInstrumentation( experiment );

	// no events sent before drawer open
	assert.true( experiment.send.notCalled );

	mw.hook( 'skin.minerva.watchstarCtaDrawer.open' ).fire();

	// eslint-disable-next-line camelcase
	assert.true( experiment.send.calledOnceWith( 'init', { action_subtype: 'init_sign_up' } ) );

	// once drawer is open, test login and signup clicks
	this.login.click();

	// eslint-disable-next-line camelcase
	assert.true( experiment.send.calledWithExactly( 'click', { action_subtype: 'login' } ) );
	assert.true( experiment.send.calledTwice );

	this.signup.click();

	// eslint-disable-next-line camelcase
	assert.true( experiment.send.calledWithExactly( 'click', { action_subtype: 'sign_up' } ) );
	assert.true( experiment.send.calledThrice );
} );
