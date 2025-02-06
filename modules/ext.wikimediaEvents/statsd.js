/*!
 * mw.track subscribers for StatsD counters and timers.
 *
 * ## Stats
 *
 * These are logged in the DogStatsD format, which is optimized for use
 * with Prometheus via statsv.py and prometheus/statsd_exporter.
 *
 * ```js
 * mw.track( 'stats.mediawiki_foo_total', 42, { key1: 'quux' } );
 * // logged as mediawiki_foo_total:42|c|#key1:quux
 *
 * // The default increment is 1. These two are equivalent.
 * mw.track( 'stats.mediawiki_bar_total' );
 * mw.track( 'stats.mediawiki_bar_total', 1 );
 * ```
 *
 * ## Legacy statsd
 *
 * These do not support labels. Instead, labels are stored as unnamed
 * dot-separated portions inside the stat names. These are intended for
 * use with StatsD and Graphite.
 *
 * $wgWMEStatsdBaseUri must point to a URL that accepts query strings,
 * such as `?foo=1235ms&bar=5c&baz=42g`.
 *
 * ```js
 * mw.track( 'counter.MediaWiki.foo.quux', 5 );
 * // logged as MediaWiki.foo.quux=5c
 *
 * mw.track( 'counter.MediaWiki.bar' );
 * // Shorthand, equivalent to mw.track( 'counter.MediaWiki.bar', 1 )
 *
 * mw.track( 'timing.MediaWiki.baz', 1234.56 );
 * // logged as MediaWiki.bar=1235ms
 * // The time is assumed to be in milliseconds and is rounded to the nearest integer.
 * ```
 */
const config = require( './config.json' );
const BATCH_SIZE = 5000;
let statsdBuffer = '';
let statsdFlushPending = false;

function statsdFlush() {
	mw.eventLog.sendBeacon( config.WMEStatsdBaseUri + '?' + statsdBuffer );
	statsdBuffer = '';
}

function statsdAdd( line ) {
	if ( config.WMEStatsdBaseUri ) {
		if ( statsdBuffer && ( statsdBuffer.length + line.length ) > BATCH_SIZE ) {
			statsdFlush();
		}
		statsdBuffer += ( statsdBuffer ? '&' : '' ) + line;
		if ( !statsdFlushPending ) {
			statsdFlushPending = true;
			mw.eventLog.enqueue( () => {
				statsdFlushPending = false;
				statsdFlush();
			} );
		}
	}
}

mw.trackSubscribe( 'timing.', ( topic, time ) => {
	statsdAdd( topic.slice( 'timing.'.length ) + '=' + Math.round( time ) + 'ms' );
} );

mw.trackSubscribe( 'counter.', ( topic, count ) => {
	count = isNaN( count ) ? 1 : Math.round( count );
	statsdAdd( topic.slice( 'counter.'.length ) + '=' + count + 'c' );
} );

let statsBuffer = '';
let statsFlushPending = false;

function statsFlush() {
	mw.eventLog.sendBeacon( config.WMEStatsBeaconUri + '?' + statsBuffer );
	statsBuffer = '';
}

function statsAdd( line ) {
	if ( config.WMEStatsBeaconUri ) {
		if ( statsBuffer && ( statsBuffer.length + line.length ) > BATCH_SIZE ) {
			statsFlush();
		}
		statsBuffer += ( statsBuffer ? '%0A' : '' ) + line;
		if ( !statsFlushPending ) {
			statsFlushPending = true;
			mw.eventLog.enqueue( () => {
				statsFlushPending = false;
				statsFlush();
			} );
		}
	}
}

// Log to console and Logstash. Avoid throwing which would break the mw.trackSubscribe loop.
function error( err ) {
	mw.log.error( err );
	mw.errorLogger.logError( err );
}

function formatDogstatsd( name, value, labels = {} ) {
	// Example of produced output:
	//
	//   mediawiki_example_thing_total:42|c|#key1:value1,key2:value2
	//
	// See also:
	// * Other producer: Wikimedia\Stats\Formatters\DogStatsdFormatter in MediaWiki core
	// * Consumer: https://github.com/prometheus/statsd_exporter/blob/v0.28.0/pkg/mapper/escape.go#L21
	// * Spec: https://docs.datadoghq.com/developers/dogstatsd/datagram_shell?tab=metrics
	const rLegal = /^[A-Za-z0-9_]+$/;
	let labelStr = '';
	for ( const labelKey in labels ) {
		if ( !rLegal.test( labelKey ) ) {
			return error( new TypeError( `Invalid stat label "${ labelKey }"` ) );
		}
		let val = labels[ labelKey ];
		// It is important that we provide a reliabile and deterministic interface.
		//
		// Above we return and emit an error for invalid stat names, counts, or labels.
		// We expect stat names and labels to be static in the code, and thus mistakes there
		// are consistent and easy to find even with basic local testing.
		//
		// We avoid hard errors for label *values*, because values are likely to be dynamic
		// or conditional. Our contract would be unreliable if counters increment sometimes
		// but not other times, especially if those "other" times are not known to you
		// (e.g. local to another user's client device). By substituting invalid values
		// with the well-known "_invalid_value" placeholder, the overall counter remains
		// accurate, and the issue can be discovered and quantified in Grafana.
		// This substitution is similar to statsd_exporter's normalization, but stricter,
		// to strongly discourage high-cardinality labels.
		if ( !rLegal.test( val ) ) {
			mw.log.warn( `Invalid label value for ${ name } ${ labelKey } "${ val }"` );
			val = '_invalid_value';
		}
		labelStr += `${ !labelStr ? encodeURIComponent( '|#' ) : ',' }${ labelKey }:${ val }`;
	}
	return `${ name }:${ value }${ labelStr }`;
}

mw.trackSubscribe( 'stats.', ( topic, value, labels = {} ) => {
	const name = topic.slice( 'stats.'.length );
	let line;
	if ( /^mediawiki_[A-Za-z0-9_]+_total$/.test( name ) ) {
		if ( value === undefined ) {
			value = 1;
		}
		if ( isNaN( value ) || Math.round( value ) !== value || value < 1 ) {
			return error( new TypeError( `Invalid counter value for ${ name }` ) );
		}
		line = formatDogstatsd( name, value + '|c', labels );
	} else {
		return error( new TypeError( `Invalid stat name ${ name }` ) );
	}
	if ( line ) {
		statsAdd( line );
	}
} );
