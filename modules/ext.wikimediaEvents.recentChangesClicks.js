/*!
 * Track clicks on the Recentchanges page
 *
 * @see https://meta.wikimedia.org/wiki/Schema:ChangesListClickTracking
 * @author Roan Kattouw <rkattouw@wikimedia.org>
 */
( function ( $, mw ) {
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

		if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'Recentchanges' ) {
			$( '.mw-changeslist' ).on( 'click', 'a[href]', function ( e ) {
				var selector,
					type = 'unknown',
					$link = $( this );
				if ( e.which === 3 ) {
					return;
				}

				// Add fromrc=1 to the URL
				// DISABLED for now because it messes with link visited colors (T158458#3161869)
				/*target = new mw.Uri( $link.attr( 'href' ) );
				target.extend( { fromrc: 1 } );
				$link.attr( 'href', target.toString() );*/

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
		}

	} );
}( jQuery, mediaWiki ) );
