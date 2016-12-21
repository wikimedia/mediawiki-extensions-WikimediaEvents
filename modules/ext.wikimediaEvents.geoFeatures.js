/*!
 * Track geo/mapping feature usage
 *
 * @see https://phabricator.wikimedia.org/T103017
 * @see https://meta.wikimedia.org/wiki/Schema:GeoFeatures
 */
( function ( $, mw ) {
	var oldHide = $.fn.hide,
		// Which iframes are being tracked
		tracked = {},
		$geoHackLinks,
		$document = $( document ),
		wmaSelector = 'iframe[src^=\'//wma.wmflabs.org/iframe.html\']',
		wiwosmSelector = 'iframe#openstreetmap';

	// Override hide() to track it
	$.fn.hide = function () {
		$( this ).trigger( 'hide' );
		return oldHide.apply( this, arguments );
	};

	/**
	 * Checks whether given element is part of a title (primary) coordinate
	 *
	 * @param {jQuery} $el
	 * @return {boolean}
	 */
	function isTitleCoordinate( $el ) {
		return $el.is( '#coordinates *' );
	}

	/**
	 * Returns an unique token identifying current user
	 * Code borrowed from WikiGrok
	 *
	 * @return {string}
	 */
	function getToken() {
		var cookieName = 'GeoFeaturesUser2',
			token = mw.cookie.get( cookieName );

		if ( token ) {
			return token;
		}

		token = mw.user.generateRandomSessionId();

		mw.cookie.set( cookieName, token, { expires: 10 * 60 } );

		return token;
	}

	/**
	 * Sends tracking information
	 *
	 * @param {string} feature Feature name
	 * @param {string} action Action performed
	 * @param {boolean} titleCoordinate Whether feature is used with the title coordinate
	 * @param {string|undefined} [url] URL to follow once event has been logged
	 */
	function doTrack( feature, action, titleCoordinate, url ) {
		mw.loader.using( 'schema.GeoFeatures' ).then( function () {
			mw.eventLog.logEvent( 'GeoFeatures', {
				feature: feature,
				action: action,
				titleCoordinate: titleCoordinate,
				userToken: getToken()
			} );
		} );
		// If the event was caused by a click on a link, follow this link after a delay to give
		// the event time to be logged
		if ( url ) {
			setTimeout(
				function () {
					document.location = url;
				},
				200
			);
		}
	}

	/**
	 * Returns whether at least part of a given element is scrolled into view
	 *
	 * @param {jQuery} $el
	 * @return {boolean}
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
		$( window ).on( 'blur', function () {
			// Wait for event loop to process updates to be sure
			setTimeout( function () {
				// Fastest checks first
				if ( !tracked[ selector ]
					&& document.activeElement instanceof HTMLIFrameElement
					&& $( document.activeElement ).is( selector )
				) {
					tracked[ selector ] = true;
					doTrack( feature, 'interaction', !!$document.data( 'isPrimary-' + feature ) );
				}
			}, 0 );
		} );
	}

	/**
	 * Adds click handlers to buttons inserted via userscripts and thus appearing unpredictably
	 * late. To intercept them reliably yet soon enough to catch all the clicks on them,
	 * make several attempts 1 second apart.
	 *
	 * @param {string} selector
	 * @param {Function} callback
	 * @param {number} attemptsLeft
	 */
	function trackButton( selector, callback, attemptsLeft ) {
		if ( !attemptsLeft ) {
			return;
		}
		// Give the tool some time to load, can't hook to it cleanly because it's not in a RL module
		setTimeout(
			function () {
				mw.requestIdleCallback( function () {
					var $button = $( selector );

					if ( $button.length ) {
						$button.on( 'click', callback );
					} else {
						trackButton( selector, callback, attemptsLeft - 1 );
					}
				}, 1000 );
			},
			1000
		);
	}

	mw.requestIdleCallback( function () {
		// Track GeoHack usage
		$geoHackLinks = $( 'a[href^="//tools.wmflabs.org/geohack/geohack.php"]' );

		if ( $geoHackLinks.length ) {
			$geoHackLinks.on( 'click', function ( event ) {
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
			trackIframe( wmaSelector, 'WikiMiniAtlas' );
			mw.hook( 'WikiMiniAtlas.load' ).add( function () {
				$( '.wmamapbutton' ).on( 'click', function () {
					var $this = $( this ),
						isTitle = isTitleCoordinate( $this ),
						$container = $( wmaSelector ).parent();

					$document.data( 'isPrimary-WikiMiniAtlas', isTitle );
					if ( $container.is( ':visible' ) ) {
						doTrack( 'WikiMiniAtlas', 'open', isTitle );
						$container.one( 'hide', function () {
							doTrack( 'WikiMiniAtlas', 'close', isTitle );
						} );
					}
				} );
			} );
		}

		// Track WIWOSM usage
		$document.data( 'isPrimary-WIWOSM', true );
		trackIframe( wiwosmSelector, 'WIWOSM' );
		trackButton( '.osm-icon-coordinates',
			function () {
				var mapShown = $( wiwosmSelector ).is( ':visible' );
				doTrack( 'WIWOSM', mapShown ? 'open' : 'close', true );
			},
			5
		);

		// Track Wikivoyage maps
		( function () {
			var $map;

			function onScroll() {
				if ( isVisible( $map ) ) {
					doTrack( 'Wikivoyage', 'view', false );
					$( window ).off( 'scroll', onScroll );
				}
			}

			$map = $( '#mapwrap #mapdiv' );

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
		}() );
	} );
}( jQuery, mediaWiki ) );
