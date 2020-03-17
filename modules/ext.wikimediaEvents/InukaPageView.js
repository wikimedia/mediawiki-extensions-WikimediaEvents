/*!
 * Track page views of Inuka target users
 *
 * @see https://phabricator.wikimedia.org/T238029
 * @see https://meta.wikimedia.org/wiki/Schema:InukaPageView
 */
( function () {
	var cookieDomain = mw.config.get( 'wgWMEInukaPageViewCookiesDomain' ),
		samplingRatePerOs = mw.config.get( 'wgWMEInukaPageViewSamplingRatePerOs' ),
		userCookieExpirationDays = 30;

	mw.requestIdleCallback( function () {
		var start = mw.now(),
			sectionSelector = 'h2.section-heading',
			openedSections = {},
			msPaused = 0,
			sectionCount,
			pageNamespace,
			isMainPage,
			isSearchPage,
			referringDomain,
			pausedAt,
			userId,
			pageviewToken,
			os;

		function getOS() {
			var userAgent = navigator.userAgent;
			if ( /KaiOS/i.test( userAgent ) ) {
				return 'kaios';
			}
			if ( /android/i.test( userAgent ) ) {
				return 'android';
			}
			if ( /iPhone/.test( userAgent ) ) {
				return 'ios';
			}
			return 'unknown';
		}

		function getAndRenewCookie( name, valueFn, expires ) {
			var value = $.cookie( name );
			value = value || valueFn();
			$.cookie( name, value, {
				expires: expires,
				path: '/',
				domain: cookieDomain
			} );
			return value;
		}

		function sampled() {
			return mw.eventLog.eventInSample( samplingRatePerOs[ getOS() ] );
		}

		function getUserId() {
			return getAndRenewCookie( 'inuka-pv-u', function () {
				return sampled() ? mw.user.generateRandomSessionId() : 'excluded';
			}, userCookieExpirationDays );
		}

		function getSessionId() {
			var expires = new Date();
			expires.setHours( expires.getHours() + 1 );
			return getAndRenewCookie( 'inuka-pv-s', function () {
				return mw.user.generateRandomSessionId();
			}, expires );
		}

		function getCountry() {
			return window.Geo && typeof window.Geo.country === 'string' ? window.Geo.country : null;
		}

		function pause() {
			if ( !pausedAt ) {
				pausedAt = mw.now();
			}
		}

		function resume() {
			if ( pausedAt ) {
				msPaused += mw.now() - pausedAt;
				pausedAt = null;
			}
		}

		function logEvent() {
			var now = mw.now(),
				totalTime = now - start;

			mw.track(
				'event.InukaPageView',
				{
					/* eslint-disable camelcase */
					user_id: userId,
					session_id: getSessionId(),
					pageview_token: pageviewToken,
					client_type: os + '-web',
					referring_domain: referringDomain,
					load_dt: new Date( start ).toISOString(),
					page_open_time: Math.round( totalTime ),
					page_visible_time: Math.round( totalTime - msPaused ),
					section_count: sectionCount,
					opened_section_count: Object.keys( openedSections ).length,
					page_namespace: pageNamespace,
					is_main_page: isMainPage,
					is_search_page: isSearchPage
					/* eslint-enable camelcase */
				}
			);
		}

		if (
			!mw.config.get( 'wgWMEInukaPageViewEnabled' ) ||
			!mw.user.isAnon()
		) {
			return;
		}

		if ( /1|yes/.test( navigator.doNotTrack ) || window.doNotTrack === '1' ) {
			return;
		}

		if ( [ 'IN', 'NG', 'ZA' ].indexOf( getCountry() ) === -1 ) {
			return;
		}

		os = getOS();
		if ( [ 'android', 'ios', 'kaios' ].indexOf( os ) === -1 ) {
			return;
		}

		userId = getUserId();
		if ( userId === 'excluded' ) {
			return;
		}

		sectionCount = $( sectionSelector ).length;
		pageNamespace = mw.config.get( 'wgNamespaceNumber' );
		isMainPage = !!mw.config.get( 'wgIsMainPage' );
		isSearchPage = !!mw.config.get( 'wgIsSearchResultPage' );
		referringDomain = document.referrer.host;
		pageviewToken = mw.user.getPageviewToken();

		$( sectionSelector ).on( 'click', function () {
			var id = $( this ).find( '.mw-headline' ).attr( 'id' );
			openedSections[ id ] = true;
		} );

		if ( document.hidden ) {
			pause();
		}

		$( document ).on( 'visibilitychange', function () {
			if ( document.hidden ) {
				pause();
				logEvent();
			} else {
				resume();
			}
		} );

		window.addEventListener( 'pagehide', logEvent );
	} );
}() );
