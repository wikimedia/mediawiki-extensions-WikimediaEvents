/* eslint-env qunit */
'use strict';

var clientError = require( '../../../modules/ext.wikimediaEvents/clientError.js' );

QUnit.module( 'ext.wikimediaEvents/clientError' );

QUnit.test( 'processErrorLoggerObject', function ( assert ) {
	var expected, actual,
		error = new Error( 'foo' );

	assert.strictEqual( clientError.processErrorLoggerObject( null ), null );

	// ---

	expected = {
		errorClass: '',
		errorMessage: 'bar',
		fileUrl: 'http://localhost:8080/wiki/Bar',
		stackTrace: '',
		errorObject: undefined
	};
	actual = clientError.processErrorLoggerObject( {
		errorMessage: 'bar',
		url: 'http://localhost:8080/wiki/Bar'
	} );

	assert.deepEqual( actual, expected );

	// ---

	expected = {
		errorClass: 'Error',
		errorMessage: 'bar',
		fileUrl: 'http://localhost:8080/wiki/Bar',
		stackTrace: clientError.getNormalizedStackTraceLines( error.stack ).join( '\n' ),
		errorObject: error
	};
	actual = clientError.processErrorLoggerObject( {
		errorMessage: 'bar',
		url: 'http://localhost:8080/wiki/Bar',
		errorObject: error
	} );

	assert.propEqual( actual, expected );
} );

QUnit.test( 'processErrorInstance', function ( assert ) {
	var errorWithoutStack = new Error( 'foo' ),
		error = new Error( 'bar' ),
		expected, actual, actualFileUrl;

	assert.strictEqual( clientError.processErrorInstance( null ), null );

	// ---

	assert.strictEqual( clientError.processErrorInstance( {} ), null );

	// ---

	errorWithoutStack.stack = null;

	assert.strictEqual( clientError.processErrorInstance( errorWithoutStack ), null );

	// ---

	expected = {
		errorClass: 'Error',
		errorMessage: 'bar',
		stackTrace: clientError.getNormalizedStackTraceLines( error.stack ).join( '\n' ),
		errorObject: error
	};

	actual = clientError.processErrorInstance( error );

	actualFileUrl = actual.fileUrl;
	delete actual.fileUrl;

	assert.propEqual( actual, expected );

	var uri = null;
	try {
		uri = new mw.Uri( actualFileUrl, { strictMode: true } );
	} catch ( e ) {}
	assert.notStrictEqual( uri, null, 'The processed URL can be parsed.' );
} );

QUnit.test( 'log', function ( assert ) {
	var data, sendBeacon;

	sendBeacon = this.sandbox.mock( navigator ).expects( 'sendBeacon' )
		.once()
		.withArgs( 'http://example.com/' );
	this.sandbox.stub( mw.config, 'values', {
		wgWikiID: 'somewiki',
		wgVersion: '1.2.3',
		skin: 'vector',
		wgAction: 'view',
		wgCanonicalNamespace: 'Special',
		wgCanonicalSpecialPageName: 'Blank',
		debug: 0
	} );
	this.sandbox.stub( mw, 'user', { isAnon: sinon.stub() } );
	this.sandbox.stub( mw, 'util', { getUrl: sinon.stub() } );
	mw.user.isAnon.returns( false );

	clientError.log( 'http://example.com/', {
		errorClass: 'Error',
		errorMessage: 'bar',
		fileUrl: 'http://localhost:8080/wiki/Bar',
		stackTrace: 'foo',
		errorObject: new Error( 'bar' )
	} );

	data = JSON.parse( sendBeacon.firstCall.args[ 1 ] );
	assert.strictEqual( data.error_class, 'Error' );
	assert.strictEqual( data.message, 'bar' );
	assert.strictEqual( data.file_url, 'http://localhost:8080/wiki/Bar' );
	assert.strictEqual( data.stack_trace, 'foo' );
	assert.strictEqual( data.error_context.wiki, 'somewiki' );
	assert.strictEqual( data.error_context.version, '1.2.3' );
	assert.strictEqual( data.error_context.skin, 'vector' );
	assert.strictEqual( data.error_context.action, 'view' );
	assert.strictEqual( data.error_context.is_logged_in, 'true' );
	assert.strictEqual( data.error_context.namespace, 'Special' );
	assert.strictEqual( data.error_context.debug, 'false' );
	assert.strictEqual( data.error_context.special_page, 'Blank' );
} );
