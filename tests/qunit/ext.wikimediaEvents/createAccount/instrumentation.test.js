'use strict';

const setupInstrumentation = require( 'ext.wikimediaEvents.createAccount/instrumentation.js' );
const instrument = require( 'ext.wikimediaEvents.createAccount/useInstrument.js' );

QUnit.module( 'ext.wikimediaEvents.createAccount.instrumentation', QUnit.newMwEnvironment( {
	beforeEach() {
		this.submitInteraction = this.sandbox.spy();

		this.useInstrument = this.sandbox.stub( instrument, 'useInstrument' );
		this.useInstrument.returns( this.submitInteraction );

		this.clock = this.sandbox.useFakeTimers( 1000 );

		this.$form = $( '<form id="userlogin2">' )
			.append( '<input name="wpName" />' )
			.append( '<a href="https://www.hcaptcha.com/privacy"></a>' )
			.append( '<a href="https://www.hcaptcha.com/terms"></a>' );

		$( '#qunit-fixture' ).append( this.$form );
	},

	afterEach() {
		this.clock.restore();
		this.useInstrument.restore();
	}
} ) );

QUnit.test( 'should submit interaction event for field changes', function ( assert ) {
	setupInstrumentation();

	this.$form.find( 'input[name=wpName]' ).trigger( 'change' );

	assert.true( this.submitInteraction.calledOnce );
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'type', { source: 'form', elementId: 'user_name' } ]
	);
} );

QUnit.test( 'should instrument interaction start and time spent on individual fields', function ( assert ) {
	setupInstrumentation();

	// Simulate the user waiting some time before interacting with the form.
	this.clock.tick( 2643 );
	this.$form.find( 'input[name=wpName]' ).trigger( 'focus' );
	// Simulate the user having spent some time on this field.
	this.clock.tick( 1318 );
	this.$form.find( 'input[name=wpName]' ).trigger( 'blur' );

	assert.true( this.submitInteraction.calledThrice );
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
		[ 'blur', { source: 'form', elementId: 'user_name', context: 1.318 } ]
	);
} );

QUnit.test( 'should submit interaction event on submit', function ( assert ) {
	setupInstrumentation();

	this.$form.find( 'input[name=wpName]' ).trigger( 'focus' );
	// Simulate the user having spent some time on the form.
	this.clock.tick( 2812 );
	// Don't trigger navigation when "submitting" the form.
	this.$form.on( 'submit', ( event ) => event.preventDefault() );
	this.$form.trigger( 'submit' );

	assert.true( this.submitInteraction.calledThrice );
	assert.deepEqual(
		this.submitInteraction.thirdCall.args,
		[ 'click', { source: 'form', subType: 'submit', context: 2.812 } ]
	);
} );

QUnit.test( 'should submit interaction event when privacy policy link is clicked', function ( assert ) {
	setupInstrumentation();

	this.$form.find( 'a[href="https://www.hcaptcha.com/privacy"]' ).trigger( 'click' );

	assert.true( this.submitInteraction.calledOnce );
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'click', { source: 'form', context: 'hcaptcha-privacy-policy' } ]
	);
} );

QUnit.test( 'should submit interaction event when terms of use link is clicked', function ( assert ) {
	setupInstrumentation();

	this.$form.find( 'a[href="https://www.hcaptcha.com/terms"]' ).trigger( 'click' );

	assert.true( this.submitInteraction.calledOnce );
	assert.deepEqual(
		this.submitInteraction.firstCall.args,
		[ 'click', { source: 'form', context: 'hcaptcha-terms-of-use' } ]
	);
} );

QUnit.test( 'should submit interaction event for frontend validation errors', function ( assert ) {
	setupInstrumentation();

	mw.track( 'specialCreateAccount.validationErrors', [ 'some_error', 'one-other-error' ] );

	assert.true( this.submitInteraction.calledTwice );
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
} );
