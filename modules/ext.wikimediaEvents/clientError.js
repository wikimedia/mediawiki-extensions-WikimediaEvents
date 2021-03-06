/*!
 * Listen for run-time errors in client-side JavaScript,
 * and log key information to EventGate via HTTP POST.
 *
 * Launch task: https://phabricator.wikimedia.org/T235189
 */
/* eslint-disable max-len */
( function () {
	var moduleConfig = require( './config.json' ),
		// Only log up to this many errors per page (T259371)
		errorLimit = 5,
		errorCount = 0,

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
		regexWebKit = /^\s*at (?:(.*?)\()?(.*?:\d+:\d+)\)?\s*$/i,
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
		regexGecko = /^\s*(?:(.*?)(?:\(.*?\))?@)?(.*:\d+:\d+)\s*$/i;
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
		var result = [],
			lines = str.split( '\n' ),
			parts,
			i;

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
		return message && [
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
		].some( function ( m ) {
			return message.indexOf( m ) > -1;
		} );
	}

	/**
	 * Check whether error logging is supported for the current file URI
	 *
	 * @param {string} [fileUrl]
	 * @return {boolean}
	 */
	function shouldLogFileUrl( fileUrl ) {
		// file url may not be defined given cached scripts run from localStorage.
		// If not explicitly set to undefined (T266517) to support filtering but still log.
		fileUrl = fileUrl || 'undefined';
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
			fileUrl.indexOf( 'javascript:' ) === 0 ||
			// Common pattern seen in the wild. Short for "inject JS".
			fileUrl.indexOf( '/inj_js/' ) > -1 ||
			fileUrl.indexOf( 'blob:' ) === 0 ||
			fileUrl.indexOf( 'jar:' ) === 0 ||
			// from Windows file system.
			fileUrl.indexOf( 'C:\\' ) === 0 ||
			fileUrl.indexOf( 'chrome-extension://' ) === 0 ||
			fileUrl.indexOf( 'safari-extension://' ) === 0 ||
			fileUrl.indexOf( 'moz-extension://' ) === 0;
	}

	/**
	 * Parses out an error descriptor from the error's stack trace.
	 *
	 * @param {Mixed} error
	 * @return {ErrorDescriptor?} If the error can be parsed, then an `ErrorDescriptor` object;
	 *  otherwise, `null`
	 */
	function processErrorInstance( error ) {
		// The 'stack' property is non-standard, so we check.
		// In some browsers it will be undefined, and in some
		// it may be an object, etc.
		var stackTraceLines,
			firstLine, parts,
			fileUrlParts, fileUrl;

		if ( !error || !( error instanceof Error ) || !error.stack ) {
			return null;
		}

		stackTraceLines = getNormalizedStackTraceLines( String( error.stack ) );

		if ( !stackTraceLines.length ) {
			return null;
		}

		firstLine = stackTraceLines[ 0 ];

		// getStackTraceLines returns lines in the form
		//
		//     at [funcName]  fileUrl:lineNo:colNo
		//
		// and we want to extract fileUrl.
		parts = firstLine.split( ' ' );
		fileUrlParts = parts[ parts.length - 1 ].split( ':' );

		// If the URL contains a port (or another unencoded ":" character?), then we need to
		// reconstruct it from the remaining parts.
		fileUrl = fileUrlParts.slice( 0, -2 ).join( ':' );

		return {
			errorClass: error.constructor.name,
			errorMessage: error.message,
			fileUrl: fileUrl,
			stackTrace: stackTraceLines.join( '\n' ),
			errorObject: error
		};
	}

	/**
	 * A simple transformation for common normalization problems.
	 * @param {string} message
	 * @return {string} normalized version of message
	 */
	function normalizeErrorMessage( message ) {
		// T262627 - drop "Uncaught" from the beginning of error messages (Chrome browser),
		// for consistency with Firefox (no "Uncaught")
		return message.replace( /^Uncaught /, '' );
	}

	/**
	 * @param {Object|null|undefined} [errorLoggerObject]
	 * @return {ErrorDescriptor|null}
	 */
	function processErrorLoggerObject( errorLoggerObject ) {
		var errorObject,
			stackTrace;

		if ( !errorLoggerObject ) {
			return null;
		}

		errorObject = errorLoggerObject.errorObject;
		stackTrace = errorObject && errorObject.stack ?
			getNormalizedStackTraceLines( errorObject.stack ).join( '\n' ) :
			'';

		return {
			errorClass: ( errorObject && errorObject.constructor.name ) || '',
			errorMessage: normalizeErrorMessage( errorLoggerObject.errorMessage ),
			fileUrl: errorLoggerObject.url,
			stackTrace: stackTrace,
			errorObject: errorObject
		};
	}

	/**
	 * Gets whether or not the error, described by an `ErrorDescriptor` object, should be logged.
	 *
	 * @param {ErrorDescriptor|null} descriptor
	 * @return {boolean}
	 */
	function shouldLog( descriptor ) {
		if ( !descriptor ) {
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

		if ( shouldLogFileUrl( descriptor.fileUrl ) ) {
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
	 * Log the error to the "mediawiki.client.error" stream on the specified EventGate instance.
	 *
	 * @param {string} intakeURL The URL of the EventGate instance
	 * @param {ErrorDescriptor} descriptor
	 */
	function log( intakeURL, descriptor ) {
		navigator.sendBeacon( intakeURL, JSON.stringify( {
			meta: {
				// Name of the stream
				stream: 'mediawiki.client.error',
				// Domain of the web page
				domain: location.hostname
			},
			// Schema used to validate events
			$schema: '/mediawiki/client/error/1.0.0',
			// Name of the error constructor
			// eslint-disable-next-line camelcase
			error_class: descriptor.errorClass,
			// Message included with the Error object
			message: descriptor.message,
			// URL of the file causing the error
			// eslint-disable-next-line camelcase
			file_url: descriptor.fileUrl,
			// URL of the web page.
			url: location.href,
			// Normalized stack trace string
			// eslint-disable-next-line camelcase
			stack_trace: descriptor.stackTrace
			// Tags that can be specified as-needed
			// tags: {}
		} ) );
	}

	/**
	 * Install a subscriber for global errors that will log an event.
	 *
	 * The diagnostic event is built from the Error object that the browser
	 * provides via window.onerror when the error occurs.
	 *
	 * @param {string} intakeURL Where to POST the error event
	 */
	function install( intakeURL ) {
		// We indirectly capture browser errors by subscribing to the
		// 'global.error' topic.
		//
		// For more information, see mediawiki.errorLogger.js in MediaWiki,
		// which is responsible for directly handling the browser's
		// global.onerror events events and producing equivalent messages to
		// the 'global.error' topic.
		mw.trackSubscribe( 'global.error', function ( _, obj ) {
			var descriptor = processErrorLoggerObject( obj );

			if ( shouldLog( descriptor ) ) {
				log( intakeURL, descriptor );
			}
		} );

		mw.trackSubscribe( 'error.vue', function ( error ) {
			var descriptor = processErrorInstance( error );

			if ( shouldLog( descriptor ) ) {
				log( intakeURL, descriptor );
			}
		} );
	}

	if ( window.QUnit ) {
		module.exports = {
			getNormalizedStackTraceLines: getNormalizedStackTraceLines,
			processErrorInstance: processErrorInstance,
			processErrorLoggerObject: processErrorLoggerObject
		};
	} else if (
		navigator.sendBeacon &&
		moduleConfig.clientErrorIntakeURL
	) {
		// Only install the logger if:
		//
		// - We're not in a testing environment;
		// - The module has been properly configured; and
		// - The client supports the necessary browser features.

		install( moduleConfig.clientErrorIntakeURL );
	}
}() );
