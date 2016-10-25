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
		// We only track 1% of the user sessions.
		// A user session id is defined in a cookie that lasts 10 minutes.
		userSampling = 100;

	/**
	 * Returns an unique token identifying current user.
	 *
	 * @return {string}
	 * @private
	 */
	function getToken() {
		// TODO: shall we change this cookie name?
		var cookieName = 'GeoFeaturesUser2',
			token = mw.cookie.get( cookieName );

		if ( token ) {
			return token;
		}

		token = mw.user.generateRandomSessionId();

		mw.cookie.set( cookieName, token, { expires: 10 * 60 } );

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
	 * @param {Object} [options]
	 * @param {number} [options.duration]
	 * @param {number} [options.sampling] Specific sampling applied to current event.
	 * @param {*} [options.extra]
	 * @private
	 */
	function logEvent( featureType, action, isFullScreen, options ) {

		var event = {
			feature: featureType,
			action: action,
			fullscreen: isFullScreen,
			mobile: isMobile,
			// we noticed a number of events get sent multiple
			// times from javascript, especially when using sendBeacon.
			// This userToken allows for later deduplication.
			userToken: userToken
		};

		options = options || {};

		if ( options.extra ) {
			event.extra = ( $.type( options.extra ) !== 'string' ) ? JSON.stringify( options.extra ) : options.extra;
		}
		if ( options.duration ) {
			event.duration = options.duration;
		}
		event.sampling = ( options.sampling || 1 ) * userSampling;
		mw.eventLog.logEvent( 'Kartographer', event );
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
				tracking = getFeatureTrack( data.feature );

			if ( !tracking ) {
				return;
			}
			options.extra = {
				route: data.feature.fullScreenRoute
			};

			switch ( data.action ) {
				case 'view':
					options.sampling = 100;
					break;
				case 'open':
				case 'hashopen':
					tracking.openedAt = this.timeStamp;
					break;
				case 'close':
					tracking.closedAt = this.timeStamp;
					options.duration = parseInt( tracking.closedAt - tracking.openedAt, 10 );
					break;
				case 'sidebar-click':
					options.extra.service = data.service;
					options.extra.type = data.type;
					break;
				case 'sidebar-type':
					options.extra.type = data.type;
					break;
			}

			if ( options.sampling && !randomOneIn( options.sampling ) ) {
				return;
			}

			mw.loader.using( [
				'ext.eventLogging',
				'schema.Kartographer'
			] ).then( function () {
				logEvent( data.feature.featureType, data.action, data.isFullScreen, options );
			} );
		} );
	} );

}( jQuery, mediaWiki ) );
