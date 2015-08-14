/**
 * Track geo/mapping feature usage
 * @see https://phabricator.wikimedia.org/T103017
 * @see https://meta.wikimedia.org/wiki/Schema:GeoFeatures
 */
( function( $, mw, undefined ) {
	var oldHide = $.fn.hide,
		// Which iframes have already been logged
		tracked = {},
		$geoHackLinks;

	// Override hide() to track it
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
	function doTrack( feature, action, titleCoordinate, url ) {
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

	/**
	 * Returns whether at least part of a given element is scrolled into view
	 * @param {jQuery} $el
	 * @returns {bool}
	 */
	function isVisible( $el ) {
		var $window = $( window ),
			top = $window.scrollTop(),
			bottom = top + $window.height(),
			elTop = $el.offset().top,
			elBottom = elTop + $el.height();

		return ( elTop >= top && elTop <= bottom ) || ( elBottom >= top && elBottom <= bottom );
	}

	/**
	 * Logs focus switches to a given iframe as interactions with a given feature
	 *
	 * @param {string} selector Selector of iframe to track
	 * @param {string} feature Feature name to log
	 */
	function trackIframe( selector, feature ) {
		$( window ).on( 'blur', function() {
			// Wait for event loop to process updates to be sure
			setTimeout( function() {
				var $el;

				// Fastest checks first
				if ( !tracked[selector]
					&& document.activeElement instanceof HTMLIFrameElement
					&& ( $el = $( document.activeElement ) ).is( selector )
				) {
					tracked[selector] = true;
					doTrack( feature, 'interaction', $el.data( 'fromPrimaryCoordinate' ) === 'yes' );
				}
			}, 0 );
		} );
	}

	// Track GeoHack usage
	$geoHackLinks = $( 'a[href^=\'//tools.wmflabs.org/geohack/geohack.php\']' );
	$geoHackLinks.on( 'click', function( event ) {
		var $this = $( this ),
			isTitle = isTitleCoordinate( $this );

		// Don't override all the weird input combinations because this may, for example,
		// result in link being opened in the same tab instead of another
		if ( event.buttons === undefined
			|| event.buttons > 1
			|| event.button
			|| event.altKey
			|| event.ctrlKey
			|| event.metaKey
			|| event.shiftKey
		) {
			doTrack( 'GeoHack', 'open', isTitle );
		} else {
			// Ordinary click, override to ensure it's logged
			doTrack( 'GeoHack', 'open', isTitle, $this.attr( 'href' ) );
			event.preventDefault();
		}
	} );

	// Track WikiMiniAtlas usage
	if ( $geoHackLinks.length ) {
		// Give WMA time to load, can't hook to it cleanly because it's not in a RL module
		setTimeout( function() {
				$( '.wmamapbutton' ).on( 'click', function() {
					var $this = $( this ),
						isTitle = isTitleCoordinate( $this ),
						$container = $( 'iframe[src^=\'//wma.wmflabs.org/iframe.html\']' ).parent();

					if ( $container.is( ':visible' ) ) {
						doTrack( 'WikiMiniAtlas', 'open', isTitle );
						$container.one( 'hide', function() {
							doTrack( 'WikiMiniAtlas', 'close', isTitle );
						} );
					}
				} );
			},
			1000
		);
	}

	// Track WIWOSM usage
	$( '.osm-icon-coordinates' ).on( 'click', function() {
		var mapShown = $( 'iframe#openstreetmap' ).is( ':visible' );
		doTrack( 'WIWOSM', mapShown ? 'open' : 'close', true );
	} );

	// Track Wikivoyage maps
	( function() {
		function onScroll() {
			if ( isVisible( $map ) ) {
				doTrack( 'Wikivoyage', 'view', false );
				$( window ).off( 'scroll', onScroll );
			}
		}

		var $map = $( '#mapwrap #mapdiv' );

		if ( !$map.length ) {
			return;
		}

		trackIframe( '#mapwrap #mapdiv iframe', 'Wikivoyage' );

		// Log only 1 of 100 views to prevent a flood
		if ( Math.random() * 100 > 1 ) {
			return;
		}

		if ( isVisible( $map ) ) {
			doTrack( 'Wikivoyage', 'view', false );
		} else {
			$( window ).on( 'scroll', onScroll );
		}
	} ) ();
}( jQuery, mediaWiki ) );
