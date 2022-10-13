<?php

/**
 * NOTE: This script should only be run in a development environment. For the sake of simplicity,
 * it assumes that it is processing requests originating from
 * `/modules/ext.wikimediaEvents/statsd.js`.
 *
 * @author Sam Smith <phuedx@wikimedia.org>
 */

$path = $_SERVER['PHP_SELF'];
$query = $_SERVER['QUERY_STRING'];

/**
 * @param string $str
 */
function wfLog( string $str ): void {
	file_put_contents( 'php://stdout', "$str\n" );
}

if ( $path !== '/beacon/statsv' ) {
	http_response_code( 404 /* Not Found */ );
	wfLog( "ERROR: Reject request to $path" );
	return;
}

parse_str( $query, $metrics );

foreach ( $metrics as $bucket => $valueType ) {
	$length = str_ends_with( $valueType, 'ms' ) ? -2 : -1;

	$value = substr( $valueType, 0, $length );
	$type = substr( $valueType, $length );

	$message = "{$bucket}:{$value}|{$type}";

	wfLog( "DEBUG: $message" );
}

http_response_code( 204 /* No Content */ );
