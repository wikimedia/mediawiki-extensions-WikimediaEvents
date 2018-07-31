/*!
 * Track clicks on the Recentchanges page
 *
 * @see https://meta.wikimedia.org/wiki/Schema:ChangesListClickTracking
 * @author Roan Kattouw <rkattouw@wikimedia.org>
 */
( function ( $, mw ) {
	var isNewUI,
		metricName,
		collapsiblePromise,
		specialPage = mw.config.get( 'wgCanonicalSpecialPageName' );

	function logReady() {
		mw.track(
			'timing.MediaWiki.timing.' + metricName + '.ready.' + specialPage,
			window.performance.now()
		);
		mw.track(
			'timing.MediaWiki.timing.' + metricName + '.backendResponse.' + specialPage,
			mw.config.get( 'wgBackendResponseTime' )
		);
	}

	if ( [ 'Recentchanges', 'Recentchangeslinked', 'Watchlist' ].indexOf( specialPage ) !== -1 ) {
		// Log performance data
		if ( window.performance && window.performance.now ) {
			// HACK: if the rcfilters module is in the 'registered' state, it's not going to be
			// loaded and we're in the old UI. If it's in the 'loading', 'loaded' or 'ready' states,
			// we're in the new UI.
			isNewUI = mw.loader.getState( 'mediawiki.rcfilters.filters.ui' ) !== 'registered';
			metricName = isNewUI ? 'structuredChangeFilters' : 'changesListSpecialPage';
			if ( isNewUI ) {
				mw.hook( 'structuredChangeFilters.ui.initialized' ).add( logReady );
			} else {
				// HACK: to measure 'ready' time, wait for makeCollapsible to be loaded
				// and for $.ready
				if ( mw.loader.getState( 'jquery.makeCollapsible' ) !== 'registered' ) {
					collapsiblePromise = mw.loader.using( 'jquery.makeCollapsible' );
				} else {
					// makeCollapsible isn't going to be loaded
					collapsiblePromise = null;
				}
				$.when( $.ready, collapsiblePromise ).done( logReady );
			}
		}
	}

	$( function () {
		var uri = new mw.Uri(),
			linkTypes = {
				'.mw-changeslist-diff': 'diff',
				'.mw-changeslist-history': 'history',
				'.mw-changeslist-title': 'page',
				'.mw-userlink': 'user',
				'.mw-usertoollinks-talk': 'talk',
				'.mw-usertoollinks-contribs': 'contribs',
				'.mw-usertoollinks-block': 'block',
				'.mw-rollback-link a': 'rollback',
				'.mw-diff-edit a': 'edit',
				'.mw-diff-undo a': 'undo',
				'.mw-thanks-thank-link': 'thank',
				'.patrollink a': 'patrol'
			};

		function trackClick( type, fromPage ) {
			mw.track( 'event.ChangesListClickTracking', {
				linkType: type,
				enhancedFiltersEnabled: !!mw.user.options.get( 'rcenhancedfilters' ),
				userId: mw.user.getId(),
				sessionId: mw.user.sessionId(),
				fromPage: fromPage,
				fromQuery: uri.getQueryString()
			} );
		}

		function getPageType() {
			if ( uri.query.action === 'history' ) {
				return 'history';
			} else if ( uri.query.diff !== undefined ) {
				return 'diff';
			}
			return 'page';
		}

		if ( specialPage === 'Recentchanges' ) {
			$( '.mw-changeslist' ).on( 'click', 'a[href]', function ( e ) {
				var selector, target,
					type = 'unknown',
					$link = $( this );
				if ( e.which === 3 ) {
					return;
				}

				// Add fromrc=1 to the URL
				target = new mw.Uri( $link.attr( 'href' ) );
				target.extend( { fromrc: 1 } );
				$link.attr( 'href', target.toString() );

				// Figure out the link type
				for ( selector in linkTypes ) {
					if ( $link.is( selector ) ) {
						type = linkTypes[ selector ];
						break;
					}
				}

				// Update current URL, the dynamic RC code could have changed it
				uri = new mw.Uri( location.href );

				// Log an event
				trackClick( type, 'Recentchanges' );
			} );

			// Click tracking for top links (T164617)
			$( '.mw-recentchanges-toplinks' ).on( 'click', 'a[href]', function ( e ) {
				var $link = $( this );

				if ( e.which === 3 ) {
					return;
				}

				mw.track( 'event.RecentChangesTopLinks', {
					url: $link.prop( 'href' ),
					label: $link.text(),
					loggedIn: !mw.user.isAnon()
				} );
			} );
		} else if ( uri.query.fromrc === '1' ) {
			$( 'body' ).on( 'click', 'a[href]', function ( e ) {
				var selector, type,
					$link = $( this );

				if ( e.which === 3 ) {
					return;
				}

				// Figure out the link type, and only log links of known types
				for ( selector in linkTypes ) {
					if ( $link.is( selector ) ) {
						type = linkTypes[ selector ];
						break;
					}
				}
				if ( type === undefined ) {
					return;
				}

				// Log an event
				trackClick( type, getPageType() );
			} );

			// Remove fromrc=1 from the URL
			delete uri.query.fromrc;
			history.replaceState( null, document.title, uri );
		}
	} );
}( jQuery, mediaWiki ) );
