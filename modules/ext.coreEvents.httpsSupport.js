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

	var pixelSrc = '//upload.wikimedia.org/wikipedia/commons/c/c0/Blank.gif';

	function inSample() {
		var factor = mw.config.get( 'wgHttpsFeatureDetectionSamplingFactor' );
		if ( !$.isNumeric( factor ) || factor < 1 ) {
			return false;
		}
		return Math.floor( Math.random() * factor ) === 0;
	}

	function pingProtocol( proto, timeout ) {
		var $beacon = $( '<img />' ),
			defer = $.Deferred();

		$beacon.on( 'load error abort timeout', defer.resolveWith );
		setTimeout( function () {
			$beacon.trigger( $.Event( 'timeout' ) );
		}, timeout || 5000 );
		$beacon.attr( 'src', proto + ':' + pixelSrc + '?' + new Date() );

		return defer.then( function () {
			var ok = this.type === 'load' && $beacon.prop( 'width' ) === 1;
			return ok ? 'success' : this.type;
		} );
	}

	// Log only if user is using HTTP and is included in the random sample.
	if ( window.location.protocol !== 'https:' && inSample() ) {
		mw.loader.using( 'schema.HttpsSupport', function () {
			$.when(
				pingProtocol( 'http' ),
				pingProtocol( 'https' )
			).done( function ( httpStatus, httpsStatus ) {
				var event = {
					httpStatus  : httpStatus,
					httpsStatus : httpsStatus,
					userAgent   : navigator.userAgent,
				};
				if ( $.isPlainObject( window.Geo ) && typeof Geo.country === 'string' ) {
					event.originCountry = Geo.country;
				}
				mw.eventLog.logEvent( 'HttpsSupport', event );
			} );
		} );
	}

} ( mediaWiki, jQuery ) );
