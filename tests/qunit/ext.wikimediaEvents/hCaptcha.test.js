'use strict';

QUnit.module( 'ext.wikimediaEvents/hCaptcha', QUnit.newMwEnvironment( {
	beforeEach() {
		// We need to stub mw.track and mw.trackSubscribe to avoid issues with
		// data pollution in between test runs, as there is no straightforward
		// way to clear the mw.track event queue.
		this.track = this.sandbox.stub( mw, 'track' );

		this.trackSubscribeHandlers = {};
		this.sandbox.stub( mw, 'trackSubscribe' ).callsFake( ( topic, handler ) => {
			this.trackSubscribeHandlers[ topic ] = this.trackSubscribeHandlers[ topic ] || [];
			this.trackSubscribeHandlers[ topic ].push( handler );
		} );

		require( 'ext.wikimediaEvents/hCaptcha.js' )();
	}
} ) );

QUnit.test( 'should ignore non-editing interfaces for hcaptcha.render() callbacks', function ( assert ) {
	const handlers = this.trackSubscribeHandlers[ 'confirmEdit.hCaptchaRenderCallback' ] || [];

	handlers.forEach( ( handler ) => handler( 'confirmEdit.hCaptchaRenderCallback', 'open', 'createaccount' ) );
	handlers.forEach( ( handler ) => handler( 'confirmEdit.hCaptchaRenderCallback', 'close', 'createaccount' ) );
	handlers.forEach( ( handler ) => handler( 'confirmEdit.hCaptchaRenderCallback', 'expired', 'createaccount' ) );

	assert.true( this.track.notCalled, '"create account" interface actions should be ignored' );
} );

QUnit.test( 'should track editing interfaces for hcaptcha.render() callbacks', function ( assert ) {
	const handlers = this.trackSubscribeHandlers[ 'confirmEdit.hCaptchaRenderCallback' ] || [];

	handlers.forEach( ( handler ) => handler( 'confirmEdit.hCaptchaRenderCallback', 'open', 'edit' ) );
	handlers.forEach( ( handler ) => handler( 'confirmEdit.hCaptchaRenderCallback', 'expired', 'edit' ) );
	handlers.forEach( ( handler ) => handler( 'confirmEdit.hCaptchaRenderCallback', 'open', 'visualeditor' ) );
	handlers.forEach( ( handler ) => handler( 'confirmEdit.hCaptchaRenderCallback', 'close', 'visualeditor' ) );

	assert.deepEqual( this.track.callCount, 6, 'edit interfaces should cause mw.track events' );
	assert.deepEqual(
		this.track.firstCall.args,
		[
			'editAttemptStep',
			// eslint-disable-next-line camelcase
			{ action: 'saveFailure', message: 'hcaptcha', type: 'captchaExtension', editor_interface: 'wikitext' }
		]
	);
	assert.deepEqual(
		this.track.secondCall.args,
		[ 'visualEditorFeatureUse', { action: 'open', feature: 'hcaptcha' } ]
	);
	assert.deepEqual(
		this.track.thirdCall.args,
		[ 'visualEditorFeatureUse', { action: 'expired', feature: 'hcaptcha' } ]
	);
	assert.deepEqual(
		this.track.getCall( 3 ).args,
		[
			'editAttemptStep',
			// eslint-disable-next-line camelcase
			{ action: 'saveFailure', message: 'hcaptcha', type: 'captchaExtension', editor_interface: 'visualeditor' }
		]
	);
	assert.deepEqual(
		this.track.getCall( 4 ).args,
		[ 'visualEditorFeatureUse', { action: 'open', feature: 'hcaptcha' } ]
	);
	assert.deepEqual(
		this.track.getCall( 5 ).args,
		[ 'visualEditorFeatureUse', { action: 'close', feature: 'hcaptcha' } ]
	);
} );
