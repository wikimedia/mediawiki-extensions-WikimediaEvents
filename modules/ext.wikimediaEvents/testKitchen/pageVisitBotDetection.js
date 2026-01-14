/**
 * A simple instrument that tries to detect bots by sending events with delays.
 * If the current user agent is in sample, it will try to send events.
 * Sets "isPageview" true if this very likely resulted from a webrequest that
 *   will be marked as a pageview in the Data Lake
 *
 * NOTE: this aligned with the server-side definition 99.6% of the time when run on
 *   data from 2025-09-26 T15.
 */

const INSTRUMENT_NAME = 'bot-detection';
const SCHEMA_ID = '/analytics/product_metrics/web/base_with_ip/2.0.0';

mw.loader.using( 'ext.testKitchen' ).then( () => {
	const instrument = mw.testKitchen.getInstrument( INSTRUMENT_NAME );
	instrument.setSchemaID( SCHEMA_ID );

	const isPageview = isPageviewClientSide( {
		pageExists: !!mw.config.get( 'wgRelevantArticleId' ),
		canonicalNamespace: mw.config.get( 'wgCanonicalNamespace' ) || '',
		canonicalSpecialPage: ( mw.config.get( 'wgCanonicalSpecialPageName' ) || '' ).toLowerCase(),
		serverHostname: mw.config.get( 'wgHostname' ) || '',
		uriHost: window.location.hostname.toLowerCase(),
		uriPath: window.location.pathname,
		uriQuery: window.location.search,
		contentType: document.contentType,
		userAgent: navigator.userAgent
	} );

	const interactionData = {
		action_context: isPageview ? 'pageview' : 'other'
	};

	// if this fires, we know js is running and ad-block didn't stop it
	instrument.submitInteraction( 'page-load', interactionData );
	setTimeout( () => {
		// if this fires, we know it was not an immediate disconnect
		instrument.submitInteraction( 'after-short-delay', interactionData );
	}, 100 );
	setTimeout( () => {
		// if this fires, either a bot waits around for a long time or it's a human
		instrument.submitInteraction( 'after-delay', interactionData );
	}, 1100 );
} );

const SPECIAL_PAGES_ACCEPTED = new Set( [
	'search',
	'recentchanges',
	'version',
	'viewobject',
	'allevents'
] );

const WMF_DOMAINS = new Set( [
	'wikimedia.org',
	'wikibooks.org',
	'wikinews.org',
	'wikipedia.org',
	'wikiquote.org',
	'wikisource.org',
	'wiktionary.org',
	'wikiversity.org',
	'wikivoyage.org',
	'wikidata.org',
	'wikifunctions.org',
	'mediawiki.org',
	'wikimediafoundation.org',
	'wikiworkshop.org',
	'wmfusercontent.org',
	'wmflabs.org',
	'wmcloud.org',
	'toolforge.org'
] );

const TEXT_HTML_CONTENT_TYPES = new Set( [
	'text/html',
	'text/html; charset=iso-8859-1',
	'text/html; charset=ISO-8859-1',
	'text/html; charset=utf-8',
	'text/html; charset=UTF-8'
] );

const APP_USER_AGENT = 'WikipediaApp';
const URI_PATH_API = 'api.php';
const DEBUG_SERVERS = /mwdebug|mw-experimental/;
const URI_PATH_PATTERN = /^(\/sr(-(ec|el))?|\/w(iki)?|\/v(iew)?|\/zh(-(cn|hans|hant|hk|mo|my|sg|tw))?)\//;
// (called PATTERN in original code)
const URI_QUERY_PATTERN = /\?((cur|old)id|title|search)=/;
const URI_QUERY_UNWANTED_ACTIONS_PATTERN = /action=edit|action=submit/;
// (see below) const MAX_ADDRESS_LENGTH = 800;
const URI_HOST_WIKIMEDIA_DOMAIN_PATTERN = /^(?!doc)(advisory|commons|foundation|incubator|meta|outreach|species|strategy|usability|wikimania|wikitech|[a-zA-Z]{2,3})\.((m|mobile|wap|zero)\.)?wikimedia\.org\.?$/;
const URI_HOST_OTHER_PROJECTS_PATTERN = /^((?!test)(?!query)([a-zA-Z0-9-_]+)\.)*(wikifunctions|wikidata|mediawiki|wikimediafoundation)\.org\.?$/;
const URI_HOST_PROJECT_DOMAIN_PATTERN = /^((?!www)(?!donate)(?!arbcom)(?!sysop)([a-zA-Z][a-zA-Z0-9-_]*)\.)*wik(ibooks|inews|ipedia|iquote|isource|tionary|iversity|ivoyage)\.org\.?$/;

/**
 * @typedef {Object} PageviewDescriptor
 *  @property {string} uriHost
 *  @property {string} uriPath
 *  @property {string} uriQuery
 *  @property {string} canonicalNamespace
 *  @property {string} canonicalSpecialPage
 *  @property {boolean} pageExists
 *  @property {string} serverHostname
 *  @property {string} userAgent
 *  @property {string} contentType
 */

/**
 * An approximation of the Java logic that determines whether a webrequest
 * is a pageview.  In webrequest data we have access to X-Analytics where
 * the edge nodes set various properties, like debug=1.
 * The original definition can be found at:
 *
 *   https://gerrit.wikimedia.org/r/plugins/gitiles/analytics/refinery/source/+/refs/heads/master/
 *     refinery-core/src/main/java/org/wikimedia/analytics/refinery/core/PageviewDefinition.java
 *
 * @param {PageviewDescriptor} data input data that aims to be as similar
 *   to what we see in webrequest as possible
 * @return {boolean} whether the webrequest that led to this page render
 *   would be considered a "pageview"
 */
function isPageviewClientSide( data ) {

	try {
		const hostParts = data.uriHost.trim( '.' ).split( '.' );
		const host2LD = hostParts.slice( -2, hostParts.length ).join( '.' );

		const isSpecialPage = data.canonicalNamespace === 'Special';
		const isAcceptedSpecialPage = SPECIAL_PAGES_ACCEPTED.has( data.canonicalSpecialPage );

		// HTTP status code is success + "if this is a special page, it's allowed"
		//   Client-side we can check if the page exists, and if not, if this is a special page.
		//   Redlinks (404s that show up as 200s) will, correctly, not pass this test.
		//   TODO: are we missing any corner cases here?
		return ( data.pageExists || ( isSpecialPage && isAcceptedSpecialPage ) ) &&
		// is a WMF domain
		// (this also takes care of the "not greater than" MAX_ADDRESS_LENGTH check
		WMF_DOMAINS.has( host2LD ) &&
		// additionally matches one of the domain patterns where we expect pageviews from
		(
			URI_HOST_WIKIMEDIA_DOMAIN_PATTERN.test( data.uriHost ) ||
			URI_HOST_OTHER_PROJECTS_PATTERN.test( data.uriHost ) ||
			URI_HOST_PROJECT_DOMAIN_PATTERN.test( data.uriHost )
		) &&
		// it's not a debug session
		// TODO: is there a better way to test for this?
		!DEBUG_SERVERS.test( data.serverHostname ) &&
		// original definition checks that this is not a page preview, which would not be the case
		// here and finally include only the isWebPageview logic because we're not in an app
		(
			( !data.userAgent.includes( APP_USER_AGENT ) ) &&
			(
				TEXT_HTML_CONTENT_TYPES.has( data.contentType ) &&
				!data.uriPath.includes( URI_PATH_API )
			) &&
			( URI_PATH_PATTERN.test( data.uriPath ) || URI_QUERY_PATTERN.test( data.uriQuery ) ) &&
			( !URI_QUERY_UNWANTED_ACTIONS_PATTERN.test( data.uriQuery ) )
		);
	} catch ( error ) {
		mw.log( 'Error determining whether this was a pageview', error );
		return false;
	}
}
