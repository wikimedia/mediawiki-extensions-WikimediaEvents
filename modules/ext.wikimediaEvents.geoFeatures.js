/**
 * Track geo/mapping feature usage
 * @see https://phabricator.wikimedia.org/T103017
 * @see https://meta.wikimedia.org/wiki/Schema:GeoFeatures
 */
( function( $, mw ) {
	// Override hide() to track it
	var oldHide = $.fn.hide;
	$.fn.hide = function() {
		$( this ).trigger( 'hide' );
		return oldHide.apply( this, arguments );
	};

	/**
	 * Checks whether given element is part of a title (primary) coordinate
	 *
	 * @param {jQuery} $el
	 * @returns {bool}
	 */
	function isTitleCoordinate( $el ) {
		return $el.is( '#coordinates *' );
	}

	/**
	 * Returns an unique token identifying current user
	 * Code borrowed from WikiGrok
	 *
	 * @returns {string}
	 */
	function getToken() {
		var cookieName = 'GeoFeaturesUser',
			token = mw.cookie.get( cookieName );

		if ( token ) {
			return token;
		}

		token = mw.user.generateRandomSessionId();

		mw.cookie.set( cookieName, token, {
			expires: 90 * 24 * 3600
		} );

		return token;
	}

	/**
	 * Sends tracking information
	 *
	 * @param {string} feature Feature name
	 * @param {string} action Action performed
	 * @param {bool} titleCoordinate Whether feature is used with the title coordinate
	 * @param {string|undefined} [url] URL to follow once event has been logged
	 */
	function track( feature, action, titleCoordinate, url ) {
		mw.eventLog.logEvent( 'GeoFeatures', {
			'feature': feature,
			'action': action,
			'titleCoordinate': titleCoordinate,
			'userToken': getToken()
		} );
		// If the event was caused by a click on a link, follow this link after a delay to give
		// the event time to be logged
		if ( url ) {
			setTimeout(
				function() {
					document.location = url;
				},
				200
			);
		}
	}

	// Track GeoHack usage
	$( 'a[href^=\'//tools.wmflabs.org/geohack/geohack.php\']' ).on( 'click', function( event ) {
		var $this = $( this );
		track( 'GeoHack', 'open', isTitleCoordinate( $this ), $this.attr( 'href' ) );
		event.preventDefault();
	} );

	// Track WikiMiniAtlas usage
	$( '.wmamapbutton' ).on( 'click', function() {
		var $this = $( this ),
			isTitle = isTitleCoordinate( $this ),
			$container = $( 'iframe[src^=\'//wma.wmflabs.org/iframe.html\']' ).parent();

		if ( $container.is( ':visible' ) ) {
			track( 'WikiMiniAtlas', 'open', isTitle );
			$container.one( 'hide', function() {
				track( 'WikiMiniAtlas', 'close', isTitle );
			} );
		}
	} );

	// Track WIWOSM usage
	$( '.osm-icon-coordinates' ).on( 'click', function() {
		var mapShown = $( 'iframe#openstreetmap' ).is( ':visible' );
		track( 'WIWOSM', mapShown ? 'open' : 'close', true );
	} );
}( jQuery, mediaWiki ) );
