'use strict';

/*!
 * EditAttemptStep and VisualEditorFeatureUse event logging
 */
var config = require( './config.json' );

// Many EditAttemptStep event properties are in snake_case for historical reasons
/* eslint-disable camelcase */

// Stores data common to all events in an editing session
// (from opening an editor to saving or cancelling the edit).
// Defined in handleFirstEvent().
var session;

// Sample rates depend on the editor. These defaults are for WikiEditor, which doesn't emit an init
// event (it emits it server-side). Rates for other editors may be changed in handleInitEvent().
var easSampleRate = config.WMESchemaEditAttemptStepSamplingRate;
var easOversample = mw.config.get( 'wgWMESchemaEditAttemptStepOversample' );
var vefuSampleRate = config.WMESchemaVisualEditorFeatureUseSamplingRate;
var vefuOversample = easOversample;

/**
 * Initialize data we need to log events.
 *
 * This must be called before any other processing in this file.
 */
function handleFirstEvent() {
	session = {
		// Editing session ID.
		// Will be reset upon every session init after the first.
		// Initial value may be set in mw.config or in URL by GrowthExperiments (T238249),
		// or in a hidden input field by WikiEditor.
		editing_session_id:
			mw.config.get( 'wgWMESchemaEditAttemptStepSessionId' ) ||
			new URL( location.href ).searchParams.get( 'editingStatsId' ) ||
			// eslint-disable-next-line no-jquery/no-global-selector
			$( '#editingStatsId' ).val() ||
			mw.user.generateRandomSessionId(),

		// Editor mode ('visualeditor', 'wikitext-2017', 'wikitext'), should be defined in an init event
		editor_interface: null,
		// Editor being used ('page', 'discussiontools'), should be defined in an init event
		integration: null,
		// T249944 may someday change this to not hang from MobileFrontend
		platform: mw.config.get( 'wgMFMode' ) !== null ? 'phone' : 'desktop'
	};

	// If 'editAttemptStep' events are being logged (indicated by this function being called),
	// also log 'visualEditorFeatureUse' events, otherwise don't. This avoids unwanted logging
	// from other features that use VE internally, such as ContentTranslation. (T334157)
	mw.trackSubscribe( 'visualEditorFeatureUse', visualEditorFeatureUseHandler );
}

var firstInitDone = false;
/**
 * Update the rolling session properties from an init event, so that subsequent events don't need to
 * duplicate them. Also initialize sample rates depending on editor_interface/integration/platform.
 *
 * Unlike handleFirstEvent(), this method may not be called at all if the editor doesn't emit an
 * init event, such as WikiEditor (it emits the event server-side), so any required defaults must be
 * set up elsewhere.
 *
 * @param {Object} event
 */
function handleInitEvent( event ) {
	if ( firstInitDone ) {
		// Start a new session if this isn't the first init event
		session.editing_session_id = mw.user.generateRandomSessionId();
	}
	firstInitDone = true;

	if ( event.editor_interface ) {
		session.editor_interface = event.editor_interface;
	}
	if ( event.integration ) {
		session.integration = event.integration;
	}

	// If you look closely, some of the values below, particularly for oversamples,
	// don't make sense. Someone should one day figure out what the correct values are.
	if ( session.integration === 'discussiontools' ) {
		easSampleRate =
			config.DTSchemaEditAttemptStepSamplingRate ||
			config.WMESchemaEditAttemptStepSamplingRate;
		easOversample =
			config.DTSchemaEditAttemptStepOversample ||
			mw.config.get( 'wgWMESchemaEditAttemptStepOversample' );
		vefuSampleRate =
			config.WMESchemaVisualEditorFeatureUseSamplingRate ||
			easSampleRate;
		vefuOversample =
			easOversample;
	} else if ( session.platform === 'phone' && session.integration === 'page' ) {
		easSampleRate =
			config.WMESchemaEditAttemptStepSamplingRate;
		easOversample =
			mw.config.get( 'wgWMESchemaEditAttemptStepOversample' ) ||
			config.MFSchemaEditAttemptStepOversample === 'all' ||
			session.editor_interface === config.MFSchemaEditAttemptStepOversample;
		vefuSampleRate =
			config.WMESchemaEditAttemptStepSamplingRate;
		vefuOversample =
			mw.config.get( 'wgWMESchemaEditAttemptStepOversample' ) ||
			config.MFSchemaEditAttemptStepOversample === 'visualeditor' ||
			config.MFSchemaEditAttemptStepOversample === 'all';
	} else {
		easSampleRate = config.WMESchemaEditAttemptStepSamplingRate;
		easOversample = mw.config.get( 'wgWMESchemaEditAttemptStepOversample' );
		vefuSampleRate = config.WMESchemaVisualEditorFeatureUseSamplingRate;
		vefuOversample = easOversample;
	}
}

// Add the editing session ID to API requests using `trackEditAttemptStepSessionId: true`,
// so that events may be logged server-side.
// https://api.jquery.com/jquery.ajaxprefilter/
$.ajaxPrefilter( function ( options ) {
	if ( options.trackEditAttemptStepSessionId && session.editing_session_id ) {
		if ( options.data instanceof window.FormData ) {
			options.data.append( 'editingStatsId', session.editing_session_id );
		} else if ( typeof options.data === 'string' ) {
			options.data += '&editingStatsId=' + encodeURIComponent( session.editing_session_id );
		} else if ( options.url.indexOf( '?' ) !== -1 ) {
			options.url += '&editingStatsId=' + encodeURIComponent( session.editing_session_id );
		} else {
			mw.errorLogger.logError( new Error( 'editAttemptStep: Unable to add editingStatsId' ), 'error.wikimediaevents' );
		}
	}
} );

// If set, output details to the browser console instead of recording events.
var trackdebug = new URL( location.href ).searchParams.has( 'trackdebug' );

// Output to the browser console.
function log() {
	// eslint-disable-next-line no-console
	console.log.apply( console, arguments );
}

// Compute duration of a step, relative to previously logged event.
var timing = {};
function computeDuration( action, event, timeStamp ) {
	if ( event.timing !== undefined ) {
		return event.timing;
	}

	switch ( action ) {
		case 'ready':
			return timeStamp - timing.init;
		case 'loaded':
			return timeStamp - timing.init;
		case 'firstChange':
			return timeStamp - timing.ready;
		case 'saveIntent':
			return timeStamp - timing.ready;
		case 'saveAttempt':
			return timeStamp - timing.saveIntent;
		case 'saveSuccess':
		case 'saveFailure':
			// HERE BE DRAGONS: the caller must compute these themselves
			// for sensible results. Deliberately sabotage any attempts to
			// use the default by returning -1
			mw.log.warn( 'editAttemptStep: Do not rely on default timing value for saveSuccess/saveFailure' );
			return -1;
		case 'abort':
			switch ( event.type ) {
				case 'preinit':
					return timeStamp - timing.init;
				case 'nochange':
				case 'switchwith':
				case 'switchwithout':
				case 'switchnochange':
				case 'abandon':
				case 'pageupdate':
					return timeStamp - timing.ready;
				case 'abandonMidsave':
					return timeStamp - timing.saveAttempt;
			}
			mw.log.warn( 'editAttemptStep: Unrecognized abort type', event.type );
			return -1;
	}
	mw.log.warn( 'editAttemptStep: Unrecognized action', action );
	return -1;
}

function addABTestData( data, addToken ) {
	// DiscussionTools A/B test for logged out users
	if ( !mw.config.get( 'wgDiscussionToolsABTest' ) ) {
		return;
	}
	if ( mw.config.get( 'wgDiscussionToolsABTestBucket' ) ) {
		data.bucket = mw.config.get( 'wgDiscussionToolsABTestBucket' );
	}
	if ( mw.user.isAnon() && addToken ) {
		var token = mw.cookie.get( 'DTABid', '' );
		if ( token ) {
			data.anonymous_user_token = token;
		}
	}
}

function inSample( samplingRate ) {
	// Not using mw.eventLog.inSample() because we want to use our own editing session ID,
	// so that entire editor sessions are sampled or not, instead of single events.
	return mw.eventLog.randomTokenMatch(
		1 / samplingRate,
		session.editing_session_id
	);
}

/**
 * Log the equivalent of an EditAttemptStep event via
 * [the Metrics Platform](https://wikitech.wikimedia.org/wiki/Metrics_Platform).
 *
 * See https://phabricator.wikimedia.org/T309013.
 *
 * @param {Object} data
 * @param {string} actionPrefix
 */
function logEditViaMetricsPlatform( data, actionPrefix ) {
	var prefix;
	if ( session.integration === 'discussiontools' ) {
		prefix = 'eas.dt.';
	} else if ( session.platform === 'phone' && session.integration === 'page' ) {
		prefix = 'eas.mf.';
	} else if ( session.editor_interface === 'visualeditor' || session.editor_interface === 'wikitext-2017' ) {
		prefix = 'eas.ve.';
	} else {
		prefix = 'eas.wt.';
	}
	var eventName = prefix + actionPrefix;

	var customData = $.extend( {}, data );

	// Provided in eventName instead
	delete customData.action;

	// Sampling rate (and therefore whether a stream should oversample) is captured in the
	// stream config ($wgEventStreams).
	delete customData.is_oversample;

	// Platform can be derived from the agent_client_platform_family context attribute mixed in
	// by the JavaScript Metrics Platform Client. The context attribute will be
	// "desktop_browser" or "mobile_browser" depending on whether the MobileFrontend extension
	// has signalled that it is enabled.
	// (T249944 may someday require changing this, if 'platform' property is changed so that
	// it isn't simply based on `mw.config.get( 'wgMFMode' )`.)
	delete customData.platform;

	mw.eventLog.dispatch( eventName, customData );
}

/**
 * Edit schema
 * https://meta.wikimedia.org/wiki/Schema:EditAttemptStep
 */
var schemaEditAttemptStep = new mw.eventLog.Schema(
	'EditAttemptStep',
	// Sample rates depend on the editor, so they are set in .log() calls instead of here
	0,
	// defaults:
	{
		page_id: mw.config.get( 'wgArticleId' ),
		page_title: mw.config.get( 'wgPageName' ),
		page_ns: mw.config.get( 'wgNamespaceNumber' ),
		// eslint-disable-next-line no-jquery/no-global-selector
		revision_id: mw.config.get( 'wgRevisionId' ) || +$( 'input[name=parentRevId]' ).val() || 0,
		user_id: mw.user.getId(),
		user_class: mw.user.isAnon() ? 'IP' : undefined,
		user_editcount: mw.config.get( 'wgUserEditCount', 0 ),
		mw_version: mw.config.get( 'wgVersion' ),
		page_token: mw.user.getPageviewToken(),
		session_token: mw.user.sessionId(),
		version: 1
	}
);

/**
 * Handler for 'editAttemptStep' events.
 *
 * @param {string} topic
 * @param {Object} data
 */
function editAttemptStepHandler( topic, data ) {
	// Convert mode='source'/'visual' to interface name (only used by VisualEditor)
	if ( data && data.mode ) {
		data.editor_interface = data.mode === 'source' ? 'wikitext-2017' : 'visualeditor';
		delete data.mode;
	}

	if ( !session ) {
		handleFirstEvent();
	}

	// Update the rolling session properties
	if ( data.action === 'init' ) {
		handleInitEvent( data );
	}

	var actionPrefixMap = {
		firstChange: 'first_change',
		saveIntent: 'save_intent',
		saveAttempt: 'save_attempt',
		saveSuccess: 'save_success',
		saveFailure: 'save_failure'
	};

	var actionPrefix = actionPrefixMap[ data.action ] || data.action;
	var timeStamp = mw.now();

	// Fill in abort type based on previous events (only used by VisualEditor)
	if (
		data.action === 'abort' &&
		( data.type === 'unknown' || data.type === 'unknown-edited' )
	) {
		if (
			timing.saveAttempt &&
			timing.saveSuccess === undefined &&
			timing.saveFailure === undefined
		) {
			data.type = 'abandonMidsave';
		} else if (
			timing.init &&
			timing.ready === undefined
		) {
			data.type = 'preinit';
		} else if ( data.type === 'unknown' ) {
			data.type = 'nochange';
		} else {
			data.type = 'abandon';
		}
	}

	// Schema's kind of a mess of special properties
	if ( data.action === 'init' || data.action === 'abort' || data.action === 'saveFailure' ) {
		data[ actionPrefix + '_type' ] = data.type;
	}
	if ( data.action === 'init' || data.action === 'abort' ) {
		data[ actionPrefix + '_mechanism' ] = data.mechanism;
	}
	var duration = 0;
	if ( data.action !== 'init' ) {
		// Schema actually does have an init_timing field, but we don't want to
		// store it because it's not meaningful.
		duration = Math.round( computeDuration( data.action, data, timeStamp ) );
		data[ actionPrefix + '_timing' ] = duration;
	}
	if ( data.action === 'saveFailure' ) {
		data[ actionPrefix + '_message' ] = data.message;
	}

	// Remove renamed properties
	delete data.type;
	delete data.mechanism;
	delete data.timing;
	delete data.message;
	data.is_oversample = !inSample( easSampleRate );

	if ( data.action === 'abort' && data.abort_type !== 'switchnochange' ) {
		timing = {};
	} else {
		timing[ data.action ] = timeStamp;
	}

	// Switching between visual and source produces a chain of
	// abort/ready/loaded events and no init event, so suppress them for
	// consistency with desktop VE's logging.
	if ( data.abort_type === 'switchnochange' ) {
		// The initial abort, flagged as a switch
		return;
	}
	if ( timing.abort ) {
		// An abort was previously logged
		if ( data.action === 'ready' ) {
			// Just discard the ready
			return;
		}
		if ( data.action === 'loaded' ) {
			// Switch has finished; remove the abort timing so we stop discarding events.
			delete timing.abort;
			return;
		}
	}

	addABTestData( data, true );

	data = $.extend( {}, session, data );

	if ( trackdebug ) {
		log( topic + '.' + data.action, duration + 'ms', data, schemaEditAttemptStep.defaults );
	} else {
		schemaEditAttemptStep.log( data, easOversample ? 1 : easSampleRate );

		// T309013: Also log via the Metrics Platform:
		logEditViaMetricsPlatform( data, actionPrefix );
	}
}

/**
 * Feature use schema
 * https://meta.wikimedia.org/wiki/Schema:VisualEditorFeatureUse
 */
var schemaVisualEditorFeatureUse = new mw.eventLog.Schema(
	'VisualEditorFeatureUse',
	// Sample rates depend on the editor, so they are set in .log() calls instead of here
	0,
	// defaults:
	{
		user_id: mw.user.getId(),
		user_editcount: mw.config.get( 'wgUserEditCount', 0 )
	}
);

/**
 * Handler for 'visualEditorFeatureUse' events. Only enabled if 'editAttemptStep' events are being
 * logged.
 *
 * @param {string} topic
 * @param {Object} data
 */
function visualEditorFeatureUseHandler( topic, data ) {
	var event = {
		feature: data.feature,
		action: data.action,
		editor_interface: data.editor_interface || session.editor_interface,
		integration: data.integration || session.integration,
		platform: data.platform || session.platform,
		editingSessionId: session.editing_session_id,
		is_oversample: !inSample( vefuSampleRate )
	};

	addABTestData( event );

	if ( trackdebug ) {
		log( topic, event, schemaVisualEditorFeatureUse.defaults );
	} else {
		schemaVisualEditorFeatureUse.log( event, vefuOversample ? 1 : vefuSampleRate );

		// T309602: Also log via the Metrics Platform:
		var eventName = 'vefu.' + data.action;

		var customData = {
			feature: data.feature,
			editing_session_id: session.editing_session_id,
			editor_interface: data.editor_interface || session.editor_interface,
			integration: data.integration || session.integration
		};

		mw.eventLog.dispatch( eventName, customData );
	}

	if ( data.feature === 'editor-switch' ) {
		var editorSwitchMap = {
			'visual-desktop': 'visualeditor',
			'source-nwe-desktop': 'wikitext-2017',
			'source-desktop': 'wikitext',
			'visual-mobile': 'visualeditor',
			// source-nwe-mobile conspicuously missing
			'source-mobile': 'wikitext'
		};
		var changedEditorInterface = editorSwitchMap[ data.action ];
		// We may also log events that don't result in an editor mode being changed
		if ( changedEditorInterface ) {
			session.editor_interface = changedEditorInterface;
		}
	}
}

mw.trackSubscribe( 'editAttemptStep', editAttemptStepHandler );
