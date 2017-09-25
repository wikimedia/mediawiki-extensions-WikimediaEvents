/*!
 * Track Kartographer feature usage
 *
 * @see https://meta.wikimedia.org/wiki/Schema:Kartographer
 */
( function ( $, mw ) {

	// Track Kartographer maps
	var isMobile = mw.config.get( 'skin' ) === 'minerva',
		userToken,
		trackedFeatures = {},
		// We only track 10% of the user sessions.
		// A user session id is defined in a cookie that lasts 10 minutes.
		userSampling = 10;

	/**
	 * Returns an unique token identifying current user.
	 *
	 * @return {string}
	 * @private
	 */
	function getToken() {
		var keyName = 'wmE-GeoFeaturesUser',
			token = mw.storage.session.get( keyName ),
			parts = token && token.split( '|' ),
			now = Date.now(),
			cut = now - ( 10 * 60 * 1000 ); // 10 minutes ago

		if ( parts && parts[ 1 ] >= cut ) {
			return parts[ 0 ];
		}

		token = mw.user.generateRandomSessionId();
		mw.storage.session.set( keyName, token + '|' + now );
		return token;
	}

	// Gets the user session id.
	userToken = getToken();

	/**
	 * Determines whether the sessionId is part of the population size.
	 *
	 * @param {string} sessionId
	 * @param {number} populationSize
	 * @return {boolean}
	 * @private
	 */
	function oneIn( sessionId, populationSize ) {
		// take the first 52 bits of the rand value to match js
		// integer precision
		var parsed = parseInt( sessionId.slice( 0, 13 ), 16 );
		return parsed % populationSize === 0;
	}

	/**
	 * Determines whether a random id is part of the population size.
	 *
	 * @param {number} populationSize
	 * @return {boolean}
	 * @private
	 */
	function randomOneIn( populationSize ) {
		var rand = mw.user.generateRandomSessionId();
		return oneIn( rand, populationSize );
	}

	/**
	 * Construct and transmit to a remote server a record of some event
	 * having occurred.
	 *
	 * This method represents the client-side API of Kartographer EventLogging.
	 *
	 * @param {string} featureType
	 * @param {string} action
	 * @param {boolean} isFullScreen
	 * @param {boolean} isFirstInteraction
	 * @param {Object} [options]
	 * @param {number} [options.duration]
	 * @param {number} [options.sampling] Specific sampling applied to current event.
	 * @param {*} [options.extra]
	 * @private
	 */
	function logEvent( featureType, action, isFullScreen, isFirstInteraction, options ) {

		var event = {
			feature: featureType,
			action: action,
			fullscreen: isFullScreen,
			mobile: isMobile,
			firstInteraction: isFirstInteraction,
			// we noticed a number of events get sent multiple
			// times from javascript, especially when using sendBeacon.
			// This userToken allows for later deduplication.
			userToken: userToken
		};

		options = options || {};

		if ( options.sampling && !randomOneIn( options.sampling ) ) {
			return;
		}

		if ( options.extra ) {
			event.extra = ( typeof options.extra !== 'string' ) ? JSON.stringify( options.extra ) : options.extra;
		}
		if ( options.duration ) {
			event.duration = options.duration;
		}
		event.sampling = ( options.sampling || 1 ) * userSampling;

		mw.loader.using( [
			'ext.eventLogging',
			'schema.Kartographer'
		] ).then( function () {
			mw.eventLog.logEvent( 'Kartographer', event );
		} );
	}

	/**
	 * Simple object used to store per-feature specific data.
	 *
	 * @param {Kartographer.Box.Map|Kartographer.Link.Link} feature
	 * @return {Object} Tracking object for the feature.
	 * @private
	 */
	function getFeatureTrack( feature ) {
		var id = feature.fullScreenRoute;
		if ( !id ) {
			return;
		}
		trackedFeatures[ id ] = trackedFeatures[ id ] || {};
		return trackedFeatures[ id ];
	}

	// Is current user part of the test group?
	if ( !oneIn( userToken, userSampling ) ) {
		return;
	}

	mw.hook( 'wikipage.content' ).add( function () {

		mw.trackSubscribe( 'mediawiki.kartographer', function ( topic, data ) {
			var options = {},
				tracking = getFeatureTrack( data.feature ),
				isInteraction = false;

			if ( !tracking ) {
				return;
			}
			options.extra = {
				route: data.feature.fullScreenRoute
			};

			function isFirstInteraction( isInteraction ) {
				if ( isInteraction && !tracking.engaged ) {
					tracking.engaged = true;
					return true;
				}
				return false;
			}

			switch ( data.action ) {
				case 'initialize':
					data.feature.on( 'click contextmenu', function () {
						var opts = $.extend( {}, options, { sampling: 100 } );
						logEvent( data.feature.featureType, 'map-click', data.isFullScreen, isFirstInteraction( true ), opts );
					} );
					data.feature.on( 'zoomend', function () {
						var opts = $.extend( {}, options, { sampling: 100 } );
						logEvent( data.feature.featureType, 'zoom', data.isFullScreen, isFirstInteraction( true ), opts );
					} );
					data.feature.on( 'dragend', function () {
						var opts = $.extend( {}, options, { sampling: 100 } );
						logEvent( data.feature.featureType, 'drag', data.isFullScreen, isFirstInteraction( true ), opts );
					} );
					data.feature.on( 'popupopen', function () {
						logEvent( data.feature.featureType, 'marker-click', data.isFullScreen, isFirstInteraction( true ), options );
					} );
					data.feature.$container.on( 'click', '.leaflet-popup-content a', function () {
						var $link = $( this ),
							destination;

						if ( $link.hasClass( 'extiw' ) ) {
							destination = 'interwiki';
						} else if ( $link.hasClass( 'external' ) ) {
							destination = 'external';
						} else {
							destination = 'internal';
						}
						options = $.extend( {}, options );
						options.extra.destination = destination;

						logEvent( data.feature.featureType, 'discovery', data.isFullScreen, isFirstInteraction( true ), options );
					} );
					return;
				case 'view':
					options.sampling = 100;
					isInteraction = false;
					break;
				case 'open':
					isInteraction = true;
					tracking.openedAt = this.timeStamp;
					break;
				case 'hashopen':
					isInteraction = false;
					tracking.openedAt = this.timeStamp;
					break;
				case 'close':
					isInteraction = true;
					tracking.closedAt = this.timeStamp;
					options.duration = parseInt( tracking.closedAt - tracking.openedAt, 10 );
					break;
				case 'sidebar-click':
					isInteraction = true;
					options.extra.service = data.service;
					options.extra.type = data.type;
					break;
				case 'sidebar-type':
					isInteraction = true;
					options.extra.type = data.type;
					break;
				default:
					isInteraction = true;
					break;
			}

			if ( data.options && data.options.extra && data.action.endsWith( 'layer' ) ) {
				options.extra.layer = data.options.extra.layer;
			}

			logEvent( data.feature.featureType, data.action, data.isFullScreen, isFirstInteraction( isInteraction ), options );
		} );
	} );

}( jQuery, mediaWiki ) );
