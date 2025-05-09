'use strict';

/*!
 * EditAttemptStep and VisualEditorFeatureUse event logging
 */
const config = require( './config.json' );

const webCommon = require( './webCommon.js' );
// Many EditAttemptStep event properties are in snake_case for historical reasons

// Stores data common to all events in an editing session
// (from opening an editor to saving or cancelling the edit).
// Defined in handleFirstEvent().
let session;

// Sample rates depend on the editor. These defaults are for WikiEditor, which doesn't emit an init
// event (it emits it server-side). Rates for other editors may be changed in handleInitEvent().
let easSampleRate = config.WMESchemaEditAttemptStepSamplingRate;
let easOversample = mw.config.get( 'wgWMESchemaEditAttemptStepOversample' );
let vefuSampleRate = config.WMESchemaVisualEditorFeatureUseSamplingRate;
let vefuOversample = easOversample;

/**
 * Initialize data we need to log events.
 *
 * This must be called before any other processing in this file.
 *
 * @param {Object} event
 */
function handleFirstEvent( event ) {
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

		// Editor mode ('visualeditor', 'wikitext-2017', 'wikitext'),
		// should be defined in an init event
		editor_interface: event.editor_interface || null,
		// Editor being used ('page', 'discussiontools'), should be defined in an init event
		integration: event.integration || null,
		// T249944 may someday change this to not hang from MobileFrontend
		platform: mw.config.get( 'wgMFMode' ) !== null ? 'phone' : 'desktop'
	};

	// If 'editAttemptStep' events are being logged (indicated by this function being called),
	// also log 'visualEditorFeatureUse' events, otherwise don't. This avoids unwanted logging
	// from other features that use VE internally, such as ContentTranslation. (T334157)
	mw.trackSubscribe( 'visualEditorFeatureUse', visualEditorFeatureUseHandler );
}

let firstInitDone = false;
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
$.ajaxPrefilter( ( options ) => {
	if ( options.trackEditAttemptStepSessionId && session.editing_session_id ) {
		if ( options.data instanceof window.FormData ) {
			options.data.append( 'editingStatsId', session.editing_session_id );
		} else if ( typeof options.data === 'string' ) {
			options.data += '&editingStatsId=' + encodeURIComponent( session.editing_session_id );
		} else if ( options.url.includes( '?' ) ) {
			options.url += '&editingStatsId=' + encodeURIComponent( session.editing_session_id );
		} else {
			mw.errorLogger.logError( new Error( 'editAttemptStep: Unable to add editingStatsId' ), 'error.wikimediaevents' );
		}
	}
} );

// If set, output details to the browser console instead of recording events.
const trackdebug = new URL( location.href ).searchParams.has( 'trackdebug' );

// Output to the browser console.
function log() {
	// eslint-disable-next-line no-console
	console.log.apply( console, arguments );
}

// Compute duration of a step, relative to previously logged event.
let timing = {};
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
	// Edit check a/b test for all users
	if ( ( mw.config.get( 'wgVisualEditorConfig' ) || {} ).editCheckABTest ) {
		const bucket = mw.config.get( 'wgVisualEditorEditCheckABTestBucket' );
		if ( bucket ) {
			data.bucket = bucket;
			if ( mw.user.isAnon() && addToken ) {
				const token = mw.cookie.get( 'VEECid', '' );
				if ( token ) {
					data.anonymous_user_token = token;
				}
			}
		}
	}
}

function inSample( samplingRate ) {
	// Not using mw.eventLog.sessionInSample() because we want to use our own editing session ID,
	// so that entire editor sessions are sampled or not, instead of single events.
	return mw.eventLog.randomTokenMatch(
		1 / samplingRate,
		session.editing_session_id
	);
}

/**
 * Edit schema
 * https://meta.wikimedia.org/wiki/Schema:EditAttemptStep
 */
const schemaEditAttemptStep = new mw.eventLog.Schema(
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
		user_is_temp: mw.user.isTemp(),
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
		handleFirstEvent( data );
	}

	// Update the rolling session properties
	if ( data.action === 'init' ) {
		handleInitEvent( data );
	}

	const actionPrefixMap = {
		firstChange: 'first_change',
		saveIntent: 'save_intent',
		saveAttempt: 'save_attempt',
		saveSuccess: 'save_success',
		saveFailure: 'save_failure'
	};

	const actionPrefix = actionPrefixMap[ data.action ] || data.action;
	const timeStamp = mw.now();

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
	let duration = 0;
	if ( data.action !== 'init' ) {
		// Schema actually does have an init_timing field, but we don't want to
		// store it because it's not meaningful.
		duration = Math.round( computeDuration( data.action, data, timeStamp ) );
		// Fall back to -1 to avoid event validation issues if there was incomplete
		// timing data that resulted in a NaN; -1 is used as a signal value in this
		// field to indicate that the data shouldn't be used.
		data[ actionPrefix + '_timing' ] = isNaN( duration ) ? -1 : duration;
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

	data = Object.assign( {}, webCommon(), session, data );

	if ( trackdebug ) {
		log( topic + '.' + data.action, duration + 'ms', data, schemaEditAttemptStep.defaults );
	} else {
		schemaEditAttemptStep.log( data, easOversample ? 1 : easSampleRate );
	}
}

/**
 * Feature use schema
 * https://meta.wikimedia.org/wiki/Schema:VisualEditorFeatureUse
 */
const schemaVisualEditorFeatureUse = new mw.eventLog.Schema(
	'VisualEditorFeatureUse',
	// Sample rates depend on the editor, so they are set in .log() calls instead of here
	0,
	// defaults:
	{
		user_id: mw.user.getId(),
		user_is_temp: mw.user.isTemp(),
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
	const event = Object.assign( {}, webCommon(), {
		feature: data.feature,
		action: data.action,
		editor_interface: data.editor_interface || session.editor_interface,
		integration: data.integration || session.integration,
		platform: data.platform || session.platform,
		editingSessionId: session.editing_session_id,
		is_oversample: !inSample( vefuSampleRate )
	} );

	addABTestData( event );

	if ( trackdebug ) {
		log( topic, event, schemaVisualEditorFeatureUse.defaults );
	} else {
		schemaVisualEditorFeatureUse.log( event, vefuOversample ? 1 : vefuSampleRate );
	}

	if ( data.feature === 'editor-switch' ) {
		const editorSwitchMap = {
			'visual-desktop': 'visualeditor',
			'source-nwe-desktop': 'wikitext-2017',
			'source-desktop': 'wikitext',
			'visual-mobile': 'visualeditor',
			// source-nwe-mobile conspicuously missing
			'source-mobile': 'wikitext'
		};
		const changedEditorInterface = editorSwitchMap[ data.action ];
		// We may also log events that don't result in an editor mode being changed
		if ( changedEditorInterface ) {
			session.editor_interface = changedEditorInterface;
		}
	}
}

mw.trackSubscribe( 'editAttemptStep', editAttemptStepHandler );
