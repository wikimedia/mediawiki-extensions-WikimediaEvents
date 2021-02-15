/* eslint-env qunit */
'use strict';

var clientError = require( '../../../modules/ext.wikimediaEvents/clientError.js' );

QUnit.module( 'ext.wikimediaEvents/clientError' );

QUnit.test( 'processErrorLoggerObject', function ( assert ) {
	var expected, actual,
		error = new Error( 'foo' );

	assert.strictEqual( null, clientError.processErrorLoggerObject( null ) );

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

	assert.strictEqual( null, clientError.processErrorInstance( null ) );

	// ---

	assert.strictEqual( null, clientError.processErrorInstance( {} ) );

	// ---

	errorWithoutStack.stack = null;

	assert.strictEqual( null, clientError.processErrorInstance( errorWithoutStack ) );

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
	assert.ok( new mw.Uri( actualFileUrl, { strictMode: true } ), 'The processed URL can be parsed.' );
} );
