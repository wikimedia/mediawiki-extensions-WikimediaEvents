<?php

/**
 * NOTE: This script should only be run in a development environment. For the sake of simplicity,
 * it assumes that it is processing requests originating from
 * `/modules/ext.wikimediaEvents/statsd.js`.
 *
 * @author Sam Smith <phuedx@wikimedia.org>
 */

$STATSD_HOSTNAME = getenv( 'STATSD_HOSTNAME' );
$STATSD_PORT = intval( getenv( 'STATSD_PORT' ) ) ?? 8125;

$path = $_SERVER['PHP_SELF'];
$query = $_SERVER['QUERY_STRING'];

if ( $path !== '/beacon/statsv' ) {
	http_response_code( 404 /* Not Found */ );

	return;
}

$socket = socket_create( AF_INET, SOCK_DGRAM, SOL_UDP );

parse_str( $query, $metrics );

foreach ( $metrics as $bucket => $valueType ) {
	$length = str_ends_with( $valueType, 'ms' ) ? -2 : -1;

	$value = substr( $valueType, 0, $length );
	$type = substr( $valueType, $length );

	$message = "{$bucket}:{$value}|{$type}";

	socket_sendto(
		$socket,
		$message,
		strlen( $message ),
		0,
		$STATSD_HOSTNAME,
		$STATSD_PORT
	);
}

http_response_code( 204, /* No Content */ );
