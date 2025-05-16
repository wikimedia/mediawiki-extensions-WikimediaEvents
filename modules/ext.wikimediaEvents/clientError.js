/*!
 * Listen for run-time errors in client-side JavaScript,
 * and log key information to EventGate via HTTP POST.
 *
 * Launch task: https://phabricator.wikimedia.org/T235189
 */
/* eslint-disable max-len */
/**
 * @typedef ModuleConfig
 * @property {string} WMEClientErrorIntakeURL
 */
const moduleConfig = /** @type {ModuleConfig} */ require( /** @type {string} */ ( './config.json' ) );
// Only log up to this many errors per page (T259371)
const errorLimit = 5;
let errorCount = 0;

// Browser stack trace strings are usually provided on the Error object,
// and render each stack frame on its own line, e.g.:
//
// WebKit browsers:
//      "at foo (http://w.org/ex.js:11:22)"
//
// Gecko browsers:
//      "foo@http://w.org/ex.js:11:22"
//
// The format is not standardized, but the two given above predominate,
// reflecting the two major browser engine lineages:
//
//          WebKit              Gecko
//          /   \                 |
//      Safari  Chrome          Firefox
//              /  |  \
//             /   |   \
//      Opera 12+ Edge Brave
//
// Given below are regular expressions that extract the "function name" and
// "location" portions of such strings.
//
// For the examples above, a successful match would yield:
//
//      [ "foo", "http://w.org/ex.js:11:22" ]
//
// This pair can then be re-composed into a new string with whatever format is desired.
//
//                 begin        end
//                 non-capture  non-capture
//                 group        group
//                     |         |
//                    /|\       /|
const regexWebKit = /^\s*at (?:(.*?)\()?(.*?:\d+:\d+)\)?\s*$/i;
//             - --       --- --   ----------- --- - -
//            / /         /    |        |       |  |  \___
//  line start /      group 1, |        |       |  |      \
//            /       function |     group 2,   |  any     line
//         any # of   name     |   url:line:col |  # of    end
//         spaces     (maybe   |                |  spaces
//                     empty)  |                |
//                             |                |
//                          literal          literal
//                            '('              ')'
//                                        (or nothing)
//
//          begin                               end
//          outer                               outer
//          non-capture                         non-capture
//          group                               group
//              \__    begin        end            |
//                 |   inner        inner          |
//                 |   non-capture  non-capture    |
//                 |   group        group          |
//                 |       |         |  ___________|
//                /|\     /|\       /| /|
const regexGecko = /^\s*(?:(.*?)(?:\(.*?\))?@)?(.*:\d+:\d+)\s*$/i;
//            - --    ---    -- - --  -   ----------  - -
//           /  /      /      | |  \_  \_      |      |_ \__ line
//  line start /   group 1,   | |    |   | group 2,     |    end
//            /    function   | args |   | url:line:col |
//       any # of  name       |      |   |              |
//       spaces    (maybe     |      | literal         any
//                 empty)     |      |  '@'            # of
//                            |      |                 spaces
//                         literal  literal
//                           '('      ')'

/**
 * @typedef ErrorDescriptor
 * @property {string} errorClass The class of the underlying error, e.g. `"Error"`
 * @property {string} errorMessage The error message
 * @property {string} fileUrl The URL of the file that the underlying error originated in
 * @property {string} [stackTrace] The normalized stack trace (see
 *  `getNormalizedStackTraceLines()`)
 * @property {Error} [errorObject] The underlying error if available
 * @property {Object} [customErrorContext] Additional custom context to be logged with the error
 */

/**
 * Convert most native stack trace strings to a common format.
 *
 * If the input string does not match a supported format,
 * the output will be an empty array.
 *
 * @private
 * @param {string} str Native stack trace string from `Error.stack`
 * @return {string[]} Normalized lines of the stack trace
 */
function getNormalizedStackTraceLines( str ) {
	const result = [];
	const lines = str.split( '\n' );
	let parts;
	let i;

	for ( i = 0; i < lines.length; i++ ) {
		// Try to boil each line of the stack trace string down to a function and
		// location pair, e.g. [ 'myFoo', 'myscript.js:1:23' ].
		// using regexes that match the WebKit-like and Gecko-like stack trace
		// formats, in that order.
		//
		// A line will match only one of the two expressions (or neither).
		// Note that in JavaScript regex, the first value in the array is
		// the original string.
		parts = regexWebKit.exec( lines[ i ] ) ||
			regexGecko.exec( lines[ i ] );

		if ( parts ) {
			// If the line was successfully matched into two parts, then re-assemble
			// the parts in our output format.
			if ( parts[ 1 ] ) {
				result.push( 'at ' + parts[ 1 ] + ' ' + parts[ 2 ] );
			} else {
				result.push( 'at ' + parts[ 2 ] );
			}
		}
	}

	return result;
}

/**
 * @param {string} message
 * @return {boolean}
 */
function shouldIgnoreMessage( message ) {
	return !!( message ) && [
		// Users unintentionally sometimes, directly or indirectly, end up running multiple scripts
		// that try to load a gadget from another site by the same name. This can cause an error if
		// those uncoordinated attempts overlap. The error is harmless to the user as both copies are
		// probably the same and they don't mind getting whichever won the race (T262493). It is hard
		// for users to centralise and coordinate such naming and state across wikis without actual
		// server-side support for the "Global gadgets" concept (T22153), and users generally have no
		// incentive to avoid these errors since it works fine for them as it is.
		// Given this mistake is fairly common among power users that view many pages, we manually
		// exclude these errors from error logging (T266720). The error must not be excluded more generally since
		// it does represent a valid error condition for Wikimedia-supported modules.
		'module already implemented: ext.gadget',
		// Ignore permission errors:
		// It is common for gadgets (or browser extensions) to create iframes and access nodes that are not
		// allowed by the current browser (T264245). There's little we can do about these errors, so
		// these should be excluded.
		'Permission denied to access property',
		'Permission denied to access object'
	].some( ( m ) => message.includes( m ) );
}

/**
 * Check whether error logging is supported for the current file URI
 *
 * @param {string} fileUrl
 * @return {boolean}
 */
function shouldIgnoreFileUrl( fileUrl ) {
	//
	// If the two URLs differ only by a fragment identifier (e.g.
	// 'example.org' vs. 'example.org#Section'), we consider them
	// to be matching.
	// Per spec, obj.url should never contain a fragment identifier,
	// yet we have observed this in the wild in several instances,
	// hence we must strip the identifier from both.
	//
	return fileUrl.split( '#' )[ 0 ] === location.href.split( '#' )[ 0 ] ||
		// Various errors originate from scripts we do not control. These may be
		// prefixed by "blob:" or "javascript:" or one of the browser extensions.
		// These are not logged but may in future be diverted
		// to another channel (see T259383 for more information).
		// eslint-disable-next-line no-script-url
		fileUrl.startsWith( 'javascript:' ) ||
		// Common pattern seen in the wild. Short for "inject JS".
		fileUrl.includes( '/inj_js/' ) ||
		fileUrl.startsWith( 'blob:' ) ||
		fileUrl.startsWith( 'jar:' ) ||
		// from Windows file system.
		fileUrl.startsWith( 'C:\\' ) ||
		fileUrl.startsWith( 'chrome://' ) ||
		fileUrl.startsWith( 'chrome-extension://' ) ||
		fileUrl.startsWith( 'safari-extension://' ) ||
		fileUrl.startsWith( 'moz-extension://' );
}

/**
 * See https://github.com/wikimedia/typescript-types/issues/48.
 *
 * @typedef UnprocessedErrorObject
 * @property {string|undefined} url?
 * @property {string} errorMessage?
 * @property {Error} errorObject?
 */

/**
 * Parses out an error descriptor from the error's stack trace.
 *
 * @param {Error|UnprocessedErrorObject|null} error The error that was caught. In theory an Error object, but it's
 *   not strictly impossible for something else to end up here.
 * @return {ErrorDescriptor?} If the error can be parsed, then an `ErrorDescriptor` object;
 *  otherwise, `null`
 */
function processErrorInstance( error ) {
	// Safety check: this method is bound to the 'error.*' mw.track prefix which is
	// fairly generic so conflicts might occur. Also, mw.errorLogger.logError() does
	// not attempt to verify that it was called with an Error, and the global error
	// handler will pass any value that was thrown, which is not restricted in
	// Javascript. Silently ignore unexpected data types.
	// Also ignore errors with no 'stack' property, which might not be present in some
	// uncommon browsers. Filtering out those events helps to reduce the noise of
	// exotic errors from fringe browsers.
	if ( !error || !( error instanceof Error ) || !error.stack ) {
		return null;
	}

	const stackTraceLines = getNormalizedStackTraceLines( String( error.stack ) );

	if ( !stackTraceLines.length ) {
		return null;
	}

	const firstLine = stackTraceLines[ 0 ];

	// getStackTraceLines returns lines in the form
	//
	//     at [funcName]  fileUrl:lineNo:colNo
	//
	// and we want to extract fileUrl.
	const parts = firstLine.split( ' ' );
	const fileUrlParts = parts[ parts.length - 1 ].split( ':' );

	// If the URL contains a port (or another unencoded ":" character?), then we need to
	// reconstruct it from the remaining parts.
	const fileUrl = fileUrlParts.slice( 0, -2 ).join( ':' );

	return {
		// @ts-ignore https://github.com/microsoft/TypeScript/issues/3841
		errorClass: error.constructor.name,
		errorMessage: error.message,
		fileUrl: fileUrl,
		stackTrace: stackTraceLines.join( '\n' ),
		errorObject: error,
		customErrorContext: /** @type {Error & {error_context?: Object}} */ ( error ).error_context
	};
}

/**
 * A simple transformation for common normalization problems.
 *
 * @param {string} message
 * @return {string} normalized version of message
 */
function normalizeErrorMessage( message ) {
	// T262627 - drop "Uncaught" from the beginning of error messages (Chrome browser),
	// for consistency with Firefox (no "Uncaught")
	return message.replace( /^Uncaught /, '' );
}

/**
 * @param {UnprocessedErrorObject|null|undefined} [errorLoggerObject]
 * @return {ErrorDescriptor|null}
 */
function processErrorLoggerObject( errorLoggerObject ) {
	if ( !errorLoggerObject ) {
		return null;
	}

	const errorObject = errorLoggerObject.errorObject;
	const stackTrace = errorObject && errorObject.stack ?
		getNormalizedStackTraceLines( errorObject.stack ).join( '\n' ) :
		'';

	return {
		// @ts-ignore https://github.com/microsoft/TypeScript/issues/3841
		errorClass: ( errorObject && errorObject.constructor.name ) || '',
		errorMessage: normalizeErrorMessage( errorLoggerObject.errorMessage ),
		// file url may not be defined given cached scripts run from localStorage.
		// If not explicitly set to undefined (T266517) to support filtering but still log.
		fileUrl: errorLoggerObject.url || 'undefined',
		stackTrace: stackTrace,
		errorObject: errorObject
	};
}

/**
 * Gets whether or not the error, described by an `ErrorDescriptor` object, should be logged.
 *
 * @param {ErrorDescriptor} descriptor
 * @return {boolean}
 */
function shouldLog( descriptor ) {
	if ( descriptor.fileUrl === 'undefined' && descriptor.errorMessage === 'Script error.' ) {
		// ScriptErrors do not have stack traces and are inactionable without file uri.
		// See T266517#6906587 for more background.
		return false;
	}

	// If we are in an iframe do not log errors. (T264245)
	try {
		if ( window.self !== window.top ) {
			return false;
		}
	} catch ( e ) {
		// permission was denied, so assume iframe.
		return false;
	}

	if ( mw.storage.session.get( 'client-error-opt-out' ) ) {
		// Invalid error object or the user has opted out of error logging.
		return false;
	}

	if ( shouldIgnoreFileUrl( descriptor.fileUrl ) ) {
		// When the error lacks a URL, or the URL is defaulted to page
		// location, the stack trace is rarely meaningful, if ever.
		//
		// It may have been censored by the browser due to cross-site
		// origin security requirements, or the code may have been
		// executed as part of an eval, or some other weird thing may
		// be happening.
		//
		// We discard such errors because without a stack trace, they
		// are not really within our power to fix. (T259369, T261523)
		//
		// If the two URLs differ only by a fragment identifier (e.g.
		// 'example.org' vs. 'example.org#Section'), we consider them
		// to be matching.
		//
		// Per spec, obj.url should never contain a fragment identifier,
		// yet we have observed this in the wild in several instances,
		// hence we must strip the identifier from both.
		//
		// Various errors originate from scripts we do not control. These may be
		// prefixed by "blob:" or one of the browser extensions.
		// These are not logged but may in future be diverted
		// to another channel (see T259383 for more information).
		return false;
	}

	// Stop repeated errors from e.g. setInterval (T259371)
	if ( errorCount >= errorLimit ) {
		return false;
	}

	errorCount++;

	if ( shouldIgnoreMessage( descriptor.errorMessage ) ) {
		return false;
	}

	return true;
}

/**
 * @typedef ErrorContext
 * @property {string} [special_page]
 * @property {string} [gadgets]
 * @property {string} component
 * @property {string} wiki
 * @property {string} version
 * @property {string} skin
 * @property {string} action
 * @property {string} is_logged_in
 * @property {string} namespace
 * @property {string} debug
 * @property {string} banner_shown
 */
/**
 * Log the error to the "mediawiki.client.error" stream on the specified EventGate instance.
 *
 * @param {string} intakeURL The URL of the EventGate instance
 * @param {ErrorDescriptor} descriptor
 * @param {string} [component] The component which logged this error
 */
function log( intakeURL, descriptor, component ) {
	let gadgets = '';
	const host = location.host;
	const protocol = location.protocol;
	const search = location.search;
	const hash = location.hash;
	const canonicalName = mw.config.get( 'wgCanonicalSpecialPageName' );
	const url = canonicalName ?
		// T266504: Rewrites URL to canonical name to allow grouping.
		// note: if URL is in form `<host>/w/index.php?title=Spécial:Préférences` this will be converted to
		// "<host>/wiki/Special:Preferences?title=Sp%C3%A9cial:Pr%C3%A9f%C3%A9rences"
		protocol + '//' + host + mw.util.getUrl( 'Special:' + canonicalName ) + search + hash :
		location.href;

	/**
	 * @typedef {Object} CentralNotice
	 * @property {Function?} isBannerShown
	 */
	// @ts-ignore https://github.com/wikimedia/typescript-types/issues/46
	const centralNotice = /** @type {CentralNotice} */ ( mw.centralNotice );
	// Extra data that can be specified as-needed. Note that the values must always be strings.
	/** @type ErrorContext */
	const errorContext = {
		component: component || 'unknown',
		wiki: mw.config.get( 'wgWikiID', '' ),
		version: mw.config.get( 'wgVersion', '' ),
		skin: mw.config.get( 'skin', '' ),
		action: mw.config.get( 'wgAction', '' ),
		is_logged_in: String( !mw.user.isAnon() ),
		namespace: mw.config.get( 'wgCanonicalNamespace', '' ),
		debug: String( !!mw.config.get( 'debug', 0 ) ),
		// T265096 - record when a banner was shown. Might be a hint to catch errors originating
		// in banner code, which is otherwise difficult to diagnose.
		banner_shown: String( (
			centralNotice &&
			// T319498: mw.centralNotice.isBannerShown might or might not exist
			centralNotice.isBannerShown &&
			centralNotice.isBannerShown()
		) || false )
	};
	if ( canonicalName ) {
		errorContext.special_page = canonicalName;
	}
	// @ts-ignore https://github.com/wikimedia/typescript-types/issues/47
	gadgets = mw.loader.getModuleNames().filter( ( module ) => module.match( /^ext\.gadget\./ ) && mw.loader.getState( module ) !== 'registered' ).map( ( /** @type string */ module ) => module.replace( /^ext\.gadget\./, '' ) ).join( ',' );
	if ( gadgets ) {
		errorContext.gadgets = gadgets;
	}

	const customErrorContext = descriptor.customErrorContext ? descriptor.customErrorContext : {};

	navigator.sendBeacon( intakeURL, JSON.stringify( {
		meta: {
			// Name of the stream
			stream: 'mediawiki.client.error',
			// Domain of the web page
			domain: location.hostname
		},
		// Schema used to validate events
		$schema: '/mediawiki/client/error/2.0.0',
		// Name of the error constructor
		error_class: descriptor.errorClass,
		// Message included with the Error object
		message: descriptor.errorMessage,
		// URL of the file causing the error
		file_url: descriptor.fileUrl,
		// URL of the web page.
		url: url,
		// Normalized stack trace string
		// We log undefined rather than empty string (consistent with file_url) to allow for filtering.
		stack_trace: descriptor.stackTrace || 'undefined',
		error_context: Object.assign( {}, errorContext, customErrorContext )
	} ) );
}

/**
 * Install a subscriber for Javascript errors that sends them to some
 * logging server.
 *
 * @param {string} intakeURL Where to POST the error event
 */
function install( intakeURL ) {
	// Capture errors which were logged manually via
	// mw.errorLogger.logError( <error>, <topic> )
	mw.trackSubscribe( 'error.', ( topic, error ) => {
		if ( topic === 'error.uncaught' ) {
			// Will be logged via global.error.
			return;
		}

		const component = topic.replace( /^error\./, '' );
		const descriptor = processErrorInstance( /** @type UnprocessedErrorObject */ ( error ) );

		if ( descriptor && shouldLog( descriptor ) ) {
			log( intakeURL, descriptor, component );
		}
	} );

	// We capture unhandled Javascript errors by subscribing to the
	// 'global.error' topic.
	//
	// For more information, see mediawiki.errorLogger.js in MediaWiki,
	// which is responsible for directly handling the browser's
	// window.onerror events and producing equivalent messages to
	// the 'global.error' topic.
	mw.trackSubscribe( 'global.error', ( _, obj ) => {
		const descriptor = processErrorLoggerObject( /** @type UnprocessedErrorObject */ ( obj ) );

		if ( descriptor && shouldLog( descriptor ) ) {
			log( intakeURL, descriptor );
		}
	} );
}

// Functionally this file is self-contained, but export some methods for testing.
module.exports = {
	getNormalizedStackTraceLines,
	processErrorInstance,
	processErrorLoggerObject,
	log
};

if (
	// @ts-ignore
	!window.QUnit &&
	navigator.sendBeacon !== undefined &&
	moduleConfig.WMEClientErrorIntakeURL
) {
	// Only install the logger if:
	//
	// - We're not in a testing environment;
	// - The module has been properly configured; and
	// - The client supports the necessary browser features.

	install( moduleConfig.WMEClientErrorIntakeURL );
}
