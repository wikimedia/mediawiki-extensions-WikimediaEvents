/* eslint camelcase: ["error", {properties: "never"}] */
/* eslint-disable jquery/no-global-selector */
/*!
 * Track citation usage events for anonymous users
 * @see https://phabricator.wikimedia.org/T191086
 * @see https://meta.wikimedia.org/wiki/Schema:CitationUsage
 * @see https://meta.wikimedia.org/wiki/Schema:CitationUsagePageLoad
 */
( function ( mwUser, mwExperiments, mwEventLog ) {
	// configuration key for the population size (one in how many?)
	var POPULATION_SIZE = mw.config.get( 'wgWMECitationUsagePopulationSize', 0 ),
		PL_POPULATION_SIZE = mw.config.get( 'wgWMECitationUsagePageLoadPopulationSize', 0 ),
		// number of milliseconds after which a 'fnHover' is logged
		HOVER_TIMEOUT = 1000,
		// these identifiers logged when they are followed by an external link
		IDENTIFIER_LABELS = [ 'DOI', 'PMID', 'PMC' ],
		SCHEMA_NAME = 'CitationUsage',
		PL_SCHEMA_NAME = 'CitationUsagePageLoad',
		REFERRER_MAX_LENGTH = 100,
		getBaseData,
		getLinkOccurence,
		getExtLinkPosition;

	// Compute once the data used with all actions.
	getBaseData = ( function () {
		var baseData;

		return function () {
			if ( !baseData ) {
				baseData = {
					dom_interactive_time: window.performance.timing.domInteractive,
					revision_id: mw.config.get( 'wgRevisionId' ),
					page_id: mw.config.get( 'wgArticleId' ),
					namespace_id: mw.config.get( 'wgNamespaceNumber' ),
					page_token: mwUser.getPageviewToken(),
					session_token: mwUser.sessionId(),
					referrer: document.referrer.slice( 0, REFERRER_MAX_LENGTH ),
					skin: mw.config.get( 'skin' ),
					mode: mw.config.get( 'wgMFMode' ) ? 'mobile' : 'desktop'
				};
			}
			return baseData;
		};
	}() );

	/**
	 * Log data to schemaName
	 * @param {string} schemaName
	 * @param {string} data
	 */
	function logEvent( schemaName, data ) {
		var baseData = getBaseData();

		mwEventLog.logEvent(
			schemaName, $.extend( {}, baseData, data, {
				event_offset_time: Math.round(
					mw.now() - baseData.dom_interactive_time
				)
			} )
		);
	}

	/**
	 * Return the number of times a link appears on the page.
	 * @param {string} href
	 * @return {number}
	 */
	getLinkOccurence = ( function () {
		var links;

		return function ( href ) {
			var $links;

			if ( !links ) {
				$links = $( '#content a[href]' );

				links = {};
				$links.each( function ( i, link ) {
					if ( link.href in links ) {
						links[ link.href ] += 1;
					} else {
						links[ link.href ] = 1;
					}
				} );
			}
			return links[ href ];
		};
	}() );

	/**
	 * Return the DOM position of an external link among other external links.
	 * If the same link appears in multiple positions, return the last
	 * position.
	 * @param {string} href
	 * @return {number}
	 */
	getExtLinkPosition = ( function () {
		var links;

		return function ( href ) {
			var $links;

			if ( !links ) {
				$links = $( '#content a.external' );

				links = {};
				$links.each( function ( i, link ) {
					links[ link.href ] = i + 1;
				} );
			}
			return links[ href ];
		};
	}() );

	/**
	 * Replace multiple whitespaces around words with one space.
	 * @param {string} text
	 * @return {string} normalized text
	 */
	function normalizeSpaces( text ) {
		return text.trim().replace( /\s+/g, ' ' );
	}

	/**
	 * Return the ID of the section to which the link belongs.
	 * @param {jQuery} $link
	 * @return {string|undefined}
	 */
	function getSectionId( $link ) {
		var $headings = $link
				.parents()
				.prevAll( 'h2:first, h3:first, h4:first, h5:first, h6:first' ),
			$headline;

		if ( $headings.length ) {
			$headline = $headings.eq( 0 ).find( '.mw-headline' );
			if ( $headline.length ) {
				return $headline.attr( 'id' );
			}
		}

		return undefined;
	}

	/**
	 * Is the link in infobox?
	 * @param {jQuery} $link
	 * @return {boolean}
	 */
	function isInInfobox( $link ) {
		return $link.parentsUntil( '#content', '.infobox' ).length > 0;
	}

	/**
	 * Return data specific to link
	 * @param {jQuery} $link
	 * @return {Object}
	 */
	function getLinkStats( $link ) {
		var href = $link.prop( 'href' );

		return {
			section_id: getSectionId( $link ),
			in_infobox: isInInfobox( $link ),
			link_text: normalizeSpaces( $link.text() ),
			link_url: href,
			link_occurrence: getLinkOccurence( href )
		};
	}

	/**
	 * Return external link statistics.
	 * @param {jQuery} $link external link
	 * @return {Object}
	 */
	function getExtLinkStats( $link ) {
		var $refText = $link.closest( '.reference-text' ),
			$prevLink = $link.prev( 'a' ),
			$linkLi = $link.parents( '.references li' ),
			data = getLinkStats( $link ),
			prevLinkText;

		if ( $refText.length ) {
			// get count of backlinks
			data.citation_in_text_refs = $refText
				.prevAll( '.mw-cite-backlink' )
				.find( 'a' )
				.length;
		}

		// get citation identifier label
		if ( $prevLink.length ) {
			prevLinkText = $prevLink.text().toUpperCase();
			if ( IDENTIFIER_LABELS.indexOf( prevLinkText ) > -1 ) {
				data.citation_identifier_label = prevLinkText;
			}
		}

		data.ext_position = getExtLinkPosition( $link.prop( 'href' ) );

		data.freely_accessible = $link
			.parent().hasClass( 'cs1-lock-free' );

		if ( $linkLi.length ) {
			data.footnote_number = $linkLi.index() + 1;
		}

		return data;
	}

	/**
	 * Log 'pageLoad' event
	 */
	function logPageLoad() {
		logEvent( PL_SCHEMA_NAME, {
			action: 'pageLoad'
		} );
	}

	/**
	 * Setup logging of actions on external links.
	 */
	function setupExtLogging() {
		$( '#content' ).on( 'click', 'a.external', function () {
			var data = getExtLinkStats( $( this ) );

			data.action = 'extClick';
			logEvent( SCHEMA_NAME, data );
		} );
	}

	/**
	 * Setup logging of actions on up links in the references list
	 */
	function setupUpLogging() {
		$( '.references' )
			.on( 'click', '.mw-cite-backlink a', function () {
				var data = getLinkStats( $( this ) );

				data.action = 'upClick';
				logEvent( SCHEMA_NAME, data );
			} );
	}

	/**
	 * Setup logging of actions on footnote links in the references list
	 */
	function setupFnLogging() {
		var hoverTimeout;

		/**
		 * Log 'fnHover' event.
		 * @param {HTMLElement} link
		 */
		function logHover( link ) {
			var data = getLinkStats( $( link ) );

			data.action = 'fnHover';
			logEvent( SCHEMA_NAME, data );
		}

		$( '#content' )
			.on( 'mouseover', 'sup.reference a', function () {
				if ( !hoverTimeout ) {
					hoverTimeout = setTimeout( logHover, HOVER_TIMEOUT, this );
				}
			} )
			.on( 'mouseout', 'sup.reference a', function () {
				clearTimeout( hoverTimeout );
				hoverTimeout = null;
			} )
			.on( 'click', 'sup.reference a', function () {
				var data = getLinkStats( $( this ) );

				clearTimeout( hoverTimeout );
				hoverTimeout = null;

				data.action = 'fnClick';
				logEvent( SCHEMA_NAME, data );
			} );
	}

	/**
	 * Whether the current session should be logged.
	 * @param {number} populationSize one in how many should be logged?
	 * @return {boolean}
	 */
	function shouldLog( populationSize ) {
		return (
			window.performance && window.performance.timing &&
				window.performance.timing.domInteractive &&
				!mw.config.get( 'wgIsMainPage' ) &&
				mw.config.get( 'wgNamespaceNumber' ) === 0 &&
				mw.config.get( 'wgAction' ) === 'view' &&
				mwUser.isAnon() &&
				mwEventLog.randomTokenMatch( populationSize, mwUser.sessionId() )
		);
	}

	$( function () {
		if ( shouldLog( PL_POPULATION_SIZE ) ) {
			mw.loader.using(
				[ 'ext.eventLogging', 'schema.' + PL_SCHEMA_NAME ],
				function () {
					mw.requestIdleCallback( logPageLoad );
				} );
		}

		if ( shouldLog( POPULATION_SIZE ) ) {
			mw.loader.using(
				[ 'ext.eventLogging', 'schema.' + SCHEMA_NAME ],
				function () {
					setupExtLogging();
					setupUpLogging();
					setupFnLogging();
				} );
		}
	} );
}( mw.user, mw.experiments, mw.eventLog ) );
