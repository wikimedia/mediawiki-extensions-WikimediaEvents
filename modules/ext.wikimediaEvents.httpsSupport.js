/*global Geo */
/**
 * JavaScript module for HTTPS feature detection.
 * Detects HTTPS support by firing two requests for the same resource
 * using HTTP for one and HTTPS by other and logs results.
 *
 * @licence GNU GPL v2 or later
 * @author Ori Livneh <ori@wikimedia.org>
 */
( function ( mw, $ ) {
	'use strict';

	var pixelSrc = '//wikimediafoundation.org/misc/blank.gif',
		requestTimeout = 10 * 1000;

	function inSample() {
		var factor = mw.config.get( 'wgHttpsFeatureDetectionSamplingFactor' );
		if ( !$.isNumeric( factor ) || factor < 1 ) {
			return false;
		}
		return Math.floor( Math.random() * factor ) === 0;
	}

	// Return a deferred object that is resolved after `ms` milliseconds.
	function sleep( ms ) {
		var defer = $.Deferred();
		setTimeout( function () {
			defer.resolve();
		}, ms );
		return defer;
	}

	function pingProtocol( proto ) {
		var $beacon = $( '<img />' ),
			defer = $.Deferred(),
			start = mw.now();

		$beacon.on( 'load error abort timeout', defer.resolveWith );
		setTimeout( function () {
			$beacon.trigger( $.Event( 'timeout' ) );
		}, requestTimeout );
		$beacon.attr( 'src', proto + ':' + pixelSrc + '?' + proto );

		return defer.then( function () {
			var status = {}, ok = this.type === 'load' && $beacon.prop( 'width' ) === 1;
			status[proto + 'Status'] = ok ? 'success' : this.type;
			status[proto + 'Timing'] = Math.round( mw.now() - start );
			return status;
		} );
	}


	// Log only if user is using HTTP and is included in the random sample.
	if ( window.location.protocol !== 'https:' && inSample() ) {
		mw.loader.using( 'schema.HttpsSupport', function () {
			var protocols = [ 'http', 'https' ];

			// Flip the order of tests 50% of the time.
			if ( Math.floor( Math.random() * 2 ) ) {
				protocols.reverse();
			}

			$.when(
				pingProtocol( protocols.pop() ),
				pingProtocol( protocols.pop() ),
				sleep( requestTimeout * 1.1 )
			).done( function ( firstStatus, secondStatus ) {
				var event = $.extend( {
					isAnon      : mw.config.get( 'wgUserId' ) === null,
					userAgent   : navigator.userAgent
				}, firstStatus, secondStatus );

				if ( mw.mobileFrontend && mw.config.exists( 'wgMFMode' ) ) {
					event.mobileMode = mw.config.get( 'wgMFMode' );
				}
				if ( $.isPlainObject( window.Geo ) ) {
					if ( typeof Geo.country === 'string' && Geo.country.length ) {
						event.originCountry = Geo.country;
					}
					if ( typeof Geo.city === 'string' && Geo.city.length ) {
						event.originCity = Geo.city;
					}
				}
				mw.eventLog.logEvent( 'HttpsSupport', event );
			} );
		} );
	}

} ( mediaWiki, jQuery ) );
