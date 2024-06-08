/* eslint-env qunit */
/* eslint-disable camelcase */
'use strict';

const clientError = require( 'ext.wikimediaEvents/clientError.js' );

QUnit.module( 'ext.wikimediaEvents/clientError', QUnit.newMwEnvironment() );

QUnit.test( 'processErrorLoggerObject', ( assert ) => {
	const error = new Error( 'foo' );
	let expected, actual;

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

QUnit.test( 'processErrorInstance', ( assert ) => {
	const errorWithoutStack = new Error( 'foo' );
	const error = new Error( 'bar' );

	assert.strictEqual( clientError.processErrorInstance( null ), null );
	assert.strictEqual( clientError.processErrorInstance( {} ), null );

	errorWithoutStack.stack = null;
	assert.strictEqual( clientError.processErrorInstance( errorWithoutStack ), null );

	const expected = {
		errorClass: 'Error',
		errorMessage: 'bar',
		stackTrace: clientError.getNormalizedStackTraceLines( error.stack ).join( '\n' ),
		errorObject: error
	};
	const actual = clientError.processErrorInstance( error );
	const actualFileUrl = actual.fileUrl;
	delete actual.fileUrl;
	assert.propEqual( actual, expected );

	let uri = null;
	try {
		uri = new mw.Uri( actualFileUrl, { strictMode: true } );
	} catch ( e ) {}
	assert.notStrictEqual( uri, null, 'The processed URL can be parsed.' );
} );

QUnit.test( 'log', function ( assert ) {
	const sendBeacon = this.sandbox.mock( navigator ).expects( 'sendBeacon' )
		.once()
		.withArgs( 'http://example.com/' );
	mw.config.set( {
		wgWikiID: 'somewiki',
		wgVersion: '1.2.3',
		skin: 'vector',
		wgAction: 'view',
		wgCanonicalNamespace: 'Special',
		wgCanonicalSpecialPageName: 'Blank',
		debug: 0
	} );
	const isAnon = this.sandbox.stub( mw.user, 'isAnon' );
	isAnon.returns( false );

	clientError.log( 'http://example.com/', {
		errorClass: 'Error',
		errorMessage: 'bar',
		fileUrl: 'http://localhost:8080/wiki/Bar',
		stackTrace: 'foo',
		errorObject: new Error( 'bar' )
	} );

	const data = JSON.parse( sendBeacon.firstCall.args[ 1 ] );
	assert.propContains( data, {
		error_class: 'Error',
		message: 'bar',
		file_url: 'http://localhost:8080/wiki/Bar',
		stack_trace: 'foo',
		error_context: {
			wiki: 'somewiki',
			version: '1.2.3',
			skin: 'vector',
			action: 'view',
			is_logged_in: 'true',
			namespace: 'Special',
			debug: 'false',
			special_page: 'Blank'
		}
	} );
} );
