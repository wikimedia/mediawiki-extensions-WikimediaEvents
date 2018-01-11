( function ( $, mw ) {

	var dwellTimings = [],
		flightTimings = [],
		upTime = {},
		downTime = {},
		mousePositions = [],
		mouseClickTimings = [],
		passwordInFocus = false,
		throttleDelay = 250,
		arrayLimiter = 1000;

	function getMouseStats() {
		var speeds = [],
			prevSlope = 0,
			prevSpeed = 0,
			curvatures = [],
			accelerations = [],
			mouseClickDeltas = [],
			point1, point2, deltaX, deltaY, dist, deltaT,
			speed, acceleration, slope, curvature, i;

		for ( i = 1; i < mousePositions.length; i++ ) {
			point1 = mousePositions[ i - 1 ];
			point2 = mousePositions[ i ];
			deltaX = 1 + Math.abs( point2.x - point1.x );
			deltaY = Math.abs( point2.y - point1.y );
			dist = Math.sqrt( deltaX * deltaX + deltaY * deltaY );
			deltaT = 1 + ( point2.t - point1.t );
			speed = dist / deltaT;
			speeds.push( speed );
			acceleration = ( speed - prevSpeed ) / deltaT;
			accelerations.push( acceleration );
			slope = deltaY / deltaX;
			curvature = ( slope - prevSlope ) / deltaX;
			curvatures.push( curvature );
			prevSlope = slope;
			prevSpeed = speed;
		}
		for ( i = 1; i < mouseClickTimings.length; i++ ) {
			mouseClickDeltas.push( mouseClickTimings[ i ] - mouseClickTimings[ i - 1 ] );
		}
		return {
			averageMouseSpeed: mw.aiCaptchaStats.mean( speeds ),
			averageMouseCurvature: mw.aiCaptchaStats.mean( curvatures ),
			averageMouseAcceleration: mw.aiCaptchaStats.mean( accelerations ),
			averageDeltaClickTime: mw.aiCaptchaStats.mean( mouseClickDeltas ),
			mouseSpeedVariance: mw.aiCaptchaStats.variance( speeds ),
			mouseCurvatureVariance: mw.aiCaptchaStats.variance( curvatures ),
			mouseAccelerationVariance: mw.aiCaptchaStats.variance( accelerations ),
			deltaClickTimeVariance: mw.aiCaptchaStats.variance( mouseClickDeltas ),
			mouseSpeedSkewness: mw.aiCaptchaStats.skewness( speeds ),
			mouseCurvatureSkewness: mw.aiCaptchaStats.skewness( curvatures ),
			mouseAccelerationSkewness: mw.aiCaptchaStats.skewness( accelerations ),
			deltaClickTimeSkewness: mw.aiCaptchaStats.skewness( mouseClickDeltas ),
			mouseSpeedKurtosis: mw.aiCaptchaStats.kurtosis( speeds ),
			mouseCurvatureKurtosis: mw.aiCaptchaStats.kurtosis( curvatures ),
			mouseAccelerationKurtosis: mw.aiCaptchaStats.kurtosis( accelerations ),
			deltaClickTimeKurtosis: mw.aiCaptchaStats.kurtosis( mouseClickDeltas ),
			mouseSpeedInterQuartileRange: mw.aiCaptchaStats.interQuartileRange( speeds ),
			mouseCurvatureInterQuartileRange: mw.aiCaptchaStats.interQuartileRange( curvatures ),
			mouseAccelerationInterQuartileRange: mw.aiCaptchaStats.interQuartileRange( accelerations ),
			deltaClickTimeInterQuartileRange: mw.aiCaptchaStats.interQuartileRange( mouseClickDeltas )
		};
	}

	function getKeyPressStats() {
		var deltaDwellTimes = [],
			deltaFlightTimes = [], i;
		for ( i = 1; i < dwellTimings.length; i++ ) {
			deltaDwellTimes.push( dwellTimings[ i ] - dwellTimings[ i - 1 ] );
		}
		for ( i = 1; i < flightTimings.length; i++ ) {
			deltaFlightTimes.push( flightTimings[ i ] - flightTimings[ i - 1 ] );
		}
		return {
			averageDeltaDwellTime: mw.aiCaptchaStats.mean( deltaDwellTimes ),
			averageDeltaFlightTime: mw.aiCaptchaStats.mean( deltaFlightTimes ),
			averageDwellTime: mw.aiCaptchaStats.mean( dwellTimings ),
			averageFlightTime: mw.aiCaptchaStats.mean( flightTimings ),
			dwellTimeVariance: mw.aiCaptchaStats.variance( dwellTimings ),
			flightTimeVariance: mw.aiCaptchaStats.variance( flightTimings ),
			dwellTimeSkewness: mw.aiCaptchaStats.skewness( dwellTimings ),
			flightTimeSkewness: mw.aiCaptchaStats.skewness( flightTimings ),
			dwellTimeKurtosis: mw.aiCaptchaStats.kurtosis( dwellTimings ),
			flightTimeKurtosis: mw.aiCaptchaStats.kurtosis( flightTimings ),
			dwellTimeInterQuartileRange: mw.aiCaptchaStats.interQuartileRange( dwellTimings ),
			flightTimeInterQuartileRange: mw.aiCaptchaStats.interQuartileRange( flightTimings )
		};
	}

	mw.loader.using( 'oojs-ui-core', function () {
		var popupDescription = mw.message( 'wikimediaevents-aicaptcha-datacollection-description' ).escaped(),
			popupFindOutMore = mw.message( 'wikimediaevents-aicaptcha-datacollection-findoutmore' ).escaped(),
			popupButton;
		popupButton = new OO.ui.PopupButtonWidget( {
			$overlay: true,
			label: mw.message( 'wikimediaevents-aicaptcha-datacollection-label' ).text(),
			icon: 'info',
			framed: false,
			popup: {
				$content: $( '<p>' + popupDescription +
					'<a href="https://meta.wikimedia.org/wiki/Research:Spambot_detection_via_registration_page_behavior">' +
					'(' + popupFindOutMore + ')</a></p>' ),
				// position: 'after',
				padded: true
			}
		} );
		popupButton.$element.css( {
			'float': 'left',
			marginTop: 30
		} );
		$( '#wpCreateaccount' ).closest( '.mw-ui-vform-field' ).append( popupButton.$element );
	} );

	$.each( [ 'wpName2', 'wpEmail', 'mw-input-captchaWord' ], function ( _, field ) {
		var now, elapsed;
		$( '#' + field ).keyup( function () {
			if ( dwellTimings.length > arrayLimiter ) {
				return;
			}
			now = new Date().getTime();
			elapsed = now - ( downTime[ field ] || now );
			dwellTimings = dwellTimings || [];
			dwellTimings.push( elapsed );
			upTime[ field ] = now;
		} );

		$( '#' + field ).keydown( function () {
			if ( flightTimings.length > arrayLimiter ) {
				return;
			}
			now = new Date().getTime();
			elapsed = now - ( upTime[ field ] || now );
			flightTimings = flightTimings || [];
			flightTimings.push( elapsed );
			downTime[ field ] = now;
		} );
	} );

	$.each( [ 'wpPassword2', 'wpRetype' ], function ( _, field ) {
		$( '#' + field ).focus( function () {
			passwordInFocus = true;
		} );

		$( '#' + field ).blur( function () {
			passwordInFocus = false;
		} );
	} );

	$( document ).mousemove( $.throttle( throttleDelay, function ( event ) {
		if ( ( !passwordInFocus ) && ( mousePositions.length <= arrayLimiter ) ) {
			mousePositions.push( {
				x: event.pageX,
				y: event.pageY,
				t: new Date().getTime()
			} );
		}
	} ) );

	$( document ).click( function () {
		if ( ( !passwordInFocus ) && ( mouseClickTimings.length <= arrayLimiter ) ) {
			mouseClickTimings.push( new Date().getTime() );
		}
	} );

	$( '#wpCreateaccount' ).click( function () {
		var mouseStats = getMouseStats(),
			keyPressStats = getKeyPressStats(),
			combinedStats = $.extend( {}, mouseStats, keyPressStats ),
			allZero = true, key, username;
		for ( key in combinedStats ) {
			if ( combinedStats[ key ] !== 0 ) {
				allZero = false;
				break;
			}
		}
		if ( allZero === true ) {
			return;
		}
		username = $( '#wpName2' ).val();
		combinedStats.userName = username;
		mw.track( 'event.InputDeviceDynamics', combinedStats );
	} );
}( jQuery, mediaWiki ) );
