const editingSessionService = require( './editingSessionService.js' );

const EXPERIMENT_NAME = 'fy25-26-we-1-7-8-suggestion-mode-beta';

// Mapping of different check actions to their purpose (accepting or declining the check)
const ACCEPT_ACTIONS = [ 'remove', 'accept', 'edit', 'fix', 'convert', 'useTarget', 'useLabel', 'recheck', 'add' ];
const DECLINE_ACTIONS = [ 'dismiss', 'keep', 'reject' ];

const experimentPromise = mw.testKitchen.getExperiment( EXPERIMENT_NAME )
	.then( ( exp ) => {
		const isUserExcluded = (
			mw.user.options.get( 'visualeditor-editcheck-suggestions' ) ||
			( mw.user.isNamed() && mw.config.get( 'wgUserEditCount' ) > 100 )
		);

		return { exp, isUserExcluded };
	} );

experimentPromise.then( ( { exp, isUserExcluded } ) => {
	if ( !( exp && exp.isAssignedGroup( 'control', 'treatment' ) ) ) {
		return;
	}

	// Do not modify the settings of any excluded user
	if ( isUserExcluded ) {
		return;
	}

	// The user is definitely enrolled in an existing experiment by this point
	mw.user.options.set( 'visualeditor-editcheck-suggestions', exp.isAssignedGroup( 'treatment' ) );
} );

mw.hook( 've.newTarget' ).add( ( target ) => {

	experimentPromise.then( ( { exp, isUserExcluded } ) => {
		if ( !( exp && exp.isAssignedGroup( 'control', 'treatment' ) ) ) {
			return;
		}

		// Skip experimentation for any excluded user
		if ( isUserExcluded ) {
			return;
		}

		const send = ( action, data ) => {
			data.funnel_entry_token = editingSessionService.getEditingSessionId( null, true );
			data.action_context = data.action_context || {};
			data.action_context.interface = target.getDefaultMode() === 'source' ? 'wikitext-2017' : 'visualeditor';
			// This needs to be a string, but we've left it as an object until
			// now so it can be easily modified:
			data.action_context = JSON.stringify( data.action_context );
			exp.send( action, data );
		};

		let abandoned = false;
		let qualifiedTimeout = null;
		let saved = false;
		let changed = false;

		const timings = {};

		function clearSession() {
			saved = false;
			abandoned = false;
			changed = false;
			if ( qualifiedTimeout !== null ) {
				clearTimeout( qualifiedTimeout );
				qualifiedTimeout = null;
			}
		}

		// Handler for any time user clicks 'edit'
		function onSessionStart() {
			exp.sendExposure();
			clearSession();
			timings.ready = mw.now();

			qualifiedTimeout = setTimeout( () => {
				if ( !abandoned ) {
					send( 'session_qualified', {
						page: {
							namespace_id: mw.config.get( 'wgNamespaceNumber' )
						}
					} );
				}
			}, 2000 );
		}

		// Entering a second edit session on the same article should count as a new session,
		// but a mobile user selecting 'edit full page' from an already-started session should not.
		// The complexity is that 'surfaceReady' events can't be interpreted the same way on mobile as on desktop.
		// NOTE: the above is technically true not just for mobile but for anywhere that VisualSectionEditing is enabled
		if ( target.enableVisualSectionEditing ) {
			target.once( 'surfaceReady', onSessionStart );
		} else {
			target.on( 'surfaceReady', onSessionStart );
		}

		target.on( 'surfaceReady', () => {
			target.surface.getModel().getDocument().once( 'transact', () => {
				if ( !changed ) {
					timings.firstChange = mw.now();
					changed = true;
					send( 'first_change', {
						action_context: { timing_ms: timings.firstChange - timings.ready }
					} );
				}
			} );
		} );

		target.on( 'save', ( data ) => {
			saved = true;
			send( 'edit_saved', {
				page: {
					namespace_id: mw.config.get( 'wgNamespaceNumber' ),
					revision_id: data.newrevid
				}
			} );
		} );

		target.on( 'teardown', () => {
			// For this experiment we're ignoring 'preinit' or 'abandonMidsave' events
			// Also must exclude normal teardown that happens after successful save
			if ( target.activating || target.saving || saved ) {
				return;
			}
			const abortType = changed ? 'abandon' : 'nochange';

			timings.abandon = mw.now();
			abandoned = true;
			send( 'abort', {
				action_subtype: abortType,
				action_context: { timing_ms: timings.abandon - timings.ready }
			} );
		} );

		mw.trackSubscribe( 'visualEditorFeatureUse', ( _, data ) => {
			if ( data && data.feature.startsWith( 'editCheck-' ) ) {
				if ( data.action.startsWith( 'suggestion-seen-' ) ) {
					const moment = data.action.replace( 'suggestion-seen-', '' );
					send( 'suggestion_seen', {
						action_subtype: moment
					} );
				}
				if ( data.action.startsWith( 'suggestion-shown-' ) ) {
					const moment = data.action.replace( 'suggestion-shown-', '' );
					send( 'suggestion_shown', {
						action_subtype: moment
					} );
				}
				if ( data.action.startsWith( 'suggestion-action-' ) ) {
					const choice = data.action.replace( 'suggestion-action-', '' );
					if ( ACCEPT_ACTIONS.includes( choice ) ) {
						send( 'suggestion_accept', {
							action_subtype: choice
						} );
					} else if ( DECLINE_ACTIONS.includes( choice ) ) {
						send( 'suggestion_decline', {
							action_subtype: choice
						} );
					}
				}
			}
		} );
	} );
} );
