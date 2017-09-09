( function ( mw, $, undefined ) {
	'use strict';

	function sample( acceptPercentage ) {
		var rand = mw.user.generateRandomSessionId(),
			// take the first 52 bits of the rand value to match js
			// integer precision
			parsed = parseInt( rand.slice( 0, 13 ), 16 );
		if ( acceptPercentage >= 1 ) {
			return true;
		}
		return parsed / Math.pow( 2, 52 ) < acceptPercentage;
	}

	function chooseOne( options ) {
		var rand = mw.user.generateRandomSessionId(),
			parsed = parseInt( rand.slice( 0, 13 ), 16 ),
			step = Math.pow( 2, 52 ) / options.length;
		return options[ Math.floor( parsed / step ) ];
	}

	// Page is not part of this test
	if ( !mw.config.exists( 'wgWMESearchRelevancePages' ) ) {
		return;
	}

	// The config value is coded into the page output and cached in varnish.
	// That means any changes to sampling rates or pages chosen will take up to
	// a week to propogate into the wild.
	var config = mw.config.get( 'wgWMESearchRelevancePages' );

	// bad configuration
	if ( !config.hasOwnProperty( 'sampleRate' ) || !config.hasOwnProperty( 'queries' ) ) {
		return;
	}

	// This page view not chosen for sampling
	if ( !sample( config.sampleRate ) ) {
		return;
	}

	function askQuestion() {
		mw.loader.using( [
			'oojs-ui-core',
			'mediawiki.notification',
			'ext.wikimediaEvents.humanSearchRel'
		] ).then( function () {
			var notification, originalClose,
				closed = false,
				query = chooseOne( config.queries ),
				question = 'wikimediaevents-humanrel-question-' + chooseOne( [ 'a', 'b', 'c', 'd' ] ),
				logEvent = function ( choice ) {
					if ( !closed ) {
						closed = true;
						notification.close();
					}
					mw.loader.using( [ 'schema.HumanSearchRelevance' ] ).then( function () {
						mw.eventLog.logEvent( 'HumanSearchRelevance', {
							articleId: mw.config.get( 'wgArticleId' ),
							query: query,
							choice: choice,
							question: question,
							mwSessionId: mw.user.sessionId()
						} );
					} );
				},
				/* global OO */
				buttons = new OO.ui.ButtonGroupWidget( {
					items: [ 'yes', 'no', 'unsure' ].map( function ( choice ) {
						return new OO.ui.ButtonWidget( {
							label: mw.message( 'wikimediaevents-humanrel-' + choice ).text()
						} ).connect( {}, {
							click: [ logEvent, choice ]
						} );
					} )
				} ),
				content = $( '<p/>', {
					'class': 'mw-wme-humanrel-question'
				} ).text( mw.message( question, query ) ),
				timeoutKey = 'wme-humrel-timeout',
				timeout = mw.storage.get( timeoutKey ),
				now = new Date().getTime();

			// Don't show the survey to same browser for 2 days, to prevent annoying users
			// While it makes sense to put this prior to loading dependencies
			// and setting up, recorded events show that 1.6% of events are
			// recorded by the same session+page id, and 2.4% of events are
			// recorded by the same session id. Not sure why, but puting the
			// check closer to actually showing the survey might help.
			if ( timeout && timeout > now ) {
				// User has seen the survey recently
				return;
			}
			// If we can't record that the survey shouldn't be duplicated, just
			// opt them out of the survey all together.
			if ( !mw.storage.set( timeoutKey, now + 2 * 86400 ) ) {
				return;
			}

			content.append( buttons.$element );
			content.append( $( '<small/>' ).append( $( '<a/>', {
				href: '//wikimediafoundation.org/wiki/Search_Relevance_Survey_Privacy_Statement',
				target: '_blank'
			} ).text( mw.message( 'wikimediaevents-humanrel-privacy-statement' ) ) ) );

			notification = mw.notification.notify( content, {
				autoHide: true,
				autoHideSeconds: 'long'
			} );

			originalClose = notification.close.bind( notification );
			notification.close = function () {
				// This is certainly fragile and depends on implementation details,
				// but for an MVP not going to refactor anything in notifications.
				// this.isPaused: true when user clicks to dismiss
				// this.isPaused: false when notification auto-hides
				if ( !closed ) {
					closed = true;
					logEvent( this.isPaused ? 'dismiss' : 'timeout' );
				}
				originalClose();
			};
		} );
	}

	setTimeout( askQuestion, 60000 );

}( mediaWiki, jQuery ) );
