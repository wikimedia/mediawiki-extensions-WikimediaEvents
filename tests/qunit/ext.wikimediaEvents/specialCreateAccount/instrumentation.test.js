'use strict';

const setupInstrumentation = require( 'ext.wikimediaEvents/specialCreateAccount/instrumentation.js' );
const instrument = require( 'ext.wikimediaEvents/specialCreateAccount/useInstrument.js' );

QUnit.module( 'ext.wikimediaEvents.specialCreateAccount.instrumentation', QUnit.newMwEnvironment( {
	beforeEach() {
		mw.config.set( {
			wgWikimediaEventsCaptchaClassType: 'hCaptcha',
			wgWikimediaEventsCreateAccountIsBlocked: false,
			wgWikimediaEventsCreateAccountIsNewRequest: true
		} );
		// We need to stub mw.track and mw.trackSubscribe to avoid issues with
		// data pollution in between test runs, as there is no straightforward
		// way to clear the mw.track event queue.
		const handlers = {};
		this.sandbox.stub( mw, 'track' ).callsFake( ( topic, ...args ) => {
			( handlers[ topic ] || [] ).forEach( ( handler ) => handler( topic, ...args ) );
		} );
		this.sandbox.stub( mw, 'trackSubscribe' ).callsFake( ( topic, handler ) => {
			handlers[ topic ] = handlers[ topic ] || [];
			handlers[ topic ].push( handler );
		} );
		this.submitInteraction = this.sandbox.spy();

		this.useInstrument = this.sandbox.stub( instrument, 'useInstrument' );
		this.useInstrument.returns( this.submitInteraction );

		this.clock = this.sandbox.useFakeTimers( 1000 );

		this.$form = $( '<form id="userlogin2">' )
			.append( '<input name="wpName" />' )
			.append( '<a href="https://www.hcaptcha.com/privacy"></a>' )
			.append( '<a href="https://www.hcaptcha.com/terms"></a>' );

		$( '#qunit-fixture' ).append( this.$form );
		setupInstrumentation();
		// Ignore the impression event, other tests check it.
		this.submitInteraction.resetHistory();
	},

	afterEach() {
		this.clock.restore();
		this.useInstrument.restore();
	}
} ) );

QUnit.test( 'should submit interaction event for field changes', function ( assert ) {
	this.$form.find( 'input[name=wpName]' ).trigger( 'change' );

	assert.true( this.submitInteraction.calledOnce );
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'type', { source: 'form', elementId: 'user_name' } ]
	);
} );

QUnit.test( 'should instrument interaction start and time spent on individual fields', function ( assert ) {
	// Simulate the user waiting some time before interacting with the form.
	this.clock.tick( 2643 );
	this.$form.find( 'input[name=wpName]' ).trigger( 'focus' );
	// Simulate the user having spent some time on this field.
	this.clock.tick( 1318 );
	this.$form.find( 'input[name=wpName]' ).trigger( 'blur' );

	assert.deepEqual( this.submitInteraction.callCount, 4 );
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'view', { source: 'form', context: 2.643 } ]
	);
	assert.deepEqual(
		this.submitInteraction.secondCall.args,
		[ 'click', { source: 'form', context: '{"return_to":""}' } ]
	);
	assert.deepEqual(
		this.submitInteraction.thirdCall.args,
		[ 'captcha_class_clientside', { source: 'form', context: 'hCaptcha' } ]
	);
	assert.deepEqual(
		this.submitInteraction.lastCall.args,
		[ 'blur', { source: 'form', elementId: 'user_name', context: 1.318 } ]
	);
} );

QUnit.test( 'should submit interaction event on submit', function ( assert ) {
	this.$form.find( 'input[name=wpName]' ).val( 'Foo ' );
	this.$form.find( 'input[name=wpName]' ).trigger( 'focus' );
	// Simulate the user having spent some time on the form.
	this.clock.tick( 2812 );
	// Don't trigger navigation when "submitting" the form.
	this.$form.on( 'submit', ( event ) => event.preventDefault() );
	this.$form.trigger( 'submit' );

	assert.deepEqual( this.submitInteraction.callCount, 5 );
	assert.deepEqual(
		this.submitInteraction.thirdCall.args,
		[ 'captcha_class_clientside', { source: 'form', context: 'hCaptcha' } ]
	);
	assert.deepEqual(
		this.submitInteraction.getCall( 3 ).args,
		[ 'click', { source: 'form', subType: 'presubmit', context: 'Foo' } ]
	);
	assert.deepEqual(
		this.submitInteraction.lastCall.args,
		[ 'click', { source: 'form', subType: 'submit', context: 2.812 } ]
	);
} );

QUnit.test( 'should submit interaction event when privacy policy link is clicked', function ( assert ) {

	this.$form.find( 'a[href="https://www.hcaptcha.com/privacy"]' ).trigger( 'click' );

	assert.true( this.submitInteraction.calledOnce );
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'click', { source: 'form', context: 'hcaptcha-privacy-policy' } ]
	);
} );

QUnit.test( 'should submit interaction event when terms of use link is clicked', function ( assert ) {
	this.$form.find( 'a[href="https://www.hcaptcha.com/terms"]' ).trigger( 'click' );

	assert.true( this.submitInteraction.calledOnce );
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'click', { source: 'form', context: 'hcaptcha-terms-of-use' } ]
	);
} );

QUnit.test( 'should submit interaction event for frontend validation errors and performance measurements', function ( assert ) {
	mw.track( 'specialCreateAccount.validationErrors', [ 'some_error', 'one-other-error' ] );
	mw.track( 'specialCreateAccount.performanceTiming', 'hcaptcha-execute', 1.718 );

	assert.true( this.submitInteraction.calledThrice );
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'view', {
			source: 'form',
			subType: 'validation_error',
			context: 'some_error'
		} ]
	);
	assert.deepEqual(
		this.submitInteraction.secondCall.args,
		[ 'view', {
			source: 'form',
			subType: 'validation_error',
			context: 'one_other_error'
		} ]
	);
	assert.deepEqual(
		this.submitInteraction.thirdCall.args,
		[ 'hcaptcha-execute', {
			source: 'form',
			context: 1.718
		} ]
	);
} );

QUnit.test( 'should submit interaction event for hcaptcha.render() callbacks', function ( assert ) {
	mw.track( 'confirmEdit.hCaptchaRenderCallback', 'open', 'createaccount' );
	assert.true( this.submitInteraction.calledOnce, '"open" interface should be tracked' );
	mw.track( 'confirmEdit.hCaptchaRenderCallback', 'open', 'edit' );
	assert.true( this.submitInteraction.calledOnce, '"edit" interface should not be tracked' );
	mw.track( 'confirmEdit.hCaptchaRenderCallback', 'expired', 'createaccount' );
	assert.true( this.submitInteraction.calledTwice, 'Two events should be created' );
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'hcaptcha_render', {
			context: 'open'
		} ],
		'The event context should be "open"'
	);
	assert.deepEqual(
		this.submitInteraction.secondCall.args,
		[ 'hcaptcha_render', {
			context: 'expired'
		} ],
		'The event context should be "expired"'
	);
} );

QUnit.test( 'should submit interaction events for errors passed to hcaptcha.render() callbacks', function ( assert ) {
	// Every call having an error should track two events
	// (one for "hcaptcha_render" and one for "hcaptcha_error")
	let expectedCount = 0;

	expectedCount += 2;
	mw.track( 'confirmEdit.hCaptchaRenderCallback', 'open', 'createaccount', 'error1' );
	assert.strictEqual(
		this.submitInteraction.callCount,
		expectedCount,
		'"open" with an error should track two events'
	);
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'hcaptcha_render', {
			context: 'open'
		} ],
		'The event context should be "open"'
	);
	assert.deepEqual(
		this.submitInteraction.secondCall.args,
		[ 'hcaptcha_error', {
			context: 'error1'
		} ],
		'The event context should contain the error'
	);

	mw.track( 'confirmEdit.hCaptchaRenderCallback', 'open', 'edit', 'error2' );
	assert.strictEqual(
		this.submitInteraction.callCount,
		expectedCount,
		'"edit" interface should not be tracked'
	);
} );

QUnit.test( 'should submit impression event on setup', function ( assert ) {
	mw.config.set( 'wgWikimediaEventsCreateAccountIsBlocked', true );
	setupInstrumentation();

	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'impression', { source: 'page', context: '{"is_blocked":true,"return_to":""}' } ]
	);
} );

QUnit.test( 'should pass the returnto value to instrumentation', function ( assert ) {
	mw.config.set( 'wgWikimediaEventsCreateAccountIsBlocked', true );
	this.sandbox.stub( URLSearchParams.prototype, 'get' )
		.callsFake( ( name ) => name === 'returnto' ? 'Main Page' : null );
	setupInstrumentation();

	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'impression', { source: 'page', context: '{"is_blocked":true,"return_to":"Main%20Page"}' } ]
	);
} );

QUnit.test( 'should not submit impression event for repeated requests', function ( assert ) {
	mw.config.set( 'wgWikimediaEventsCreateAccountIsNewRequest', false );
	setupInstrumentation();

	assert.false( this.submitInteraction.calledWith( 'impression' ) );
} );

QUnit.test.each( 'getImpressionContext', {
	'not blocked, no returnto': [ false, '', '{"is_blocked":false,"return_to":""}' ],
	'blocked, short returnto': [ true, 'Main_Page', '{"is_blocked":true,"return_to":"Main_Page"}' ],
	'long returnto': [
		false,
		'A'.repeat( 400 ),
		'{"is_blocked":false,"return_to":"' + 'A'.repeat( 200 ) + '"}'
	],
	'long returnto with escaped characters': [
		true,
		' '.repeat( 400 ),
		'{"is_blocked":true,"return_to":"' + '%20'.repeat( 400 ).slice( 0, 200 ) + '"}'
	]
}, ( assert, [ isBlocked, returnTo, expected ] ) => {
	const context = setupInstrumentation.getImpressionContext( isBlocked, returnTo );

	assert.strictEqual( context, expected );
	assert.true( context.length <= 320, 'context fits in action_context' );
} );

QUnit.test.each( 'should submit interaction event when a link on the IP block page is clicked', {
	'log in': [ '#mw-createaccount-permerror-login', 'permerror-login' ],
	continue: [ '#mw-createaccount-permerror-return', 'permerror-continue' ],
	'find out more': [ '#mw-createaccount-ipblocked-details a', 'block-more' ]
}, function ( assert, [ selector, subType ] ) {
	$( '#qunit-fixture' ).append(
		$( '<div class="mw-createaccount-ipblocked">' ).append(
			$( '<div class="mw-createaccount-permissionerror-actions">' ).append(
				'<a id="mw-createaccount-permerror-login" href="#"></a>',
				'<a id="mw-createaccount-permerror-return" href="#"></a>'
			),
			'<div id="mw-createaccount-ipblocked-details"><p><a href="#"></a></p></div>'
		)
	);
	setupInstrumentation();
	this.submitInteraction.resetHistory();

	$( selector ).trigger( 'mousedown' );

	assert.true( this.submitInteraction.calledOnce );
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'click', { source: 'page', subType, context: '{"page":"ipblocked"}' } ]
	);
} );

QUnit.test( 'should submit interaction event when a link on the error page is clicked', function ( assert ) {
	mw.config.set( 'wgErrorPageMessageKey', 'blockedtext' );
	$( '#qunit-fixture' ).append(
		$( '<div class="mw-createaccount-permissionerror-actions">' ).append(
			'<a id="mw-createaccount-permerror-login" href="#"></a>',
			'<a id="mw-createaccount-permerror-return" href="#"></a>'
		)
	);
	setupInstrumentation();
	this.submitInteraction.resetHistory();

	$( '#mw-createaccount-permerror-login' ).trigger( 'mousedown' );

	assert.true( this.submitInteraction.calledOnce );
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'click', {
			source: 'page',
			subType: 'permerror-login',
			context: '{"page":"error","message_key":"blockedtext"}'
		} ]
	);
} );
