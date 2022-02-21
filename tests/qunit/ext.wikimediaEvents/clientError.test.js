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
