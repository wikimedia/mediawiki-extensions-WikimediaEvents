const editingSessionService = require( './editingSessionService.js' );

// Mapping of different check actions to their purpose (accepting or declining the check)
// Updated to match all available actions as of Nov 2026
const ACCEPT_ACTIONS = [ 'remove', 'accept', 'edit', 'fix', 'convert', 'useTarget',
	'useLabel', 'recheck', 'add', 'merge', 'delete', 'done' ];
const DECLINE_ACTIONS = [ 'dismiss', 'keep', 'reject' ];

// (T439895) Artificial gap between sending experiment_exposure and any events
// that are at risk of arriving before it (like editor_ready)
const EXPOSURE_TO_READY_DELAY_MS = 500;

/**
 * Set up discoverability instrumentation for a suggestion mode experiment
 *
 * @param {string} experimentName
 * @param {string} treatmentGroupName
 * @param {Function} getExclusion Returns { isUserExcluded, exclusionReason }.
 *  for an experiment to define its own eligibility rules
 */
module.exports = function setUpSuggestionDiscoverabilityExperiment(
	experimentName, treatmentGroupName, getExclusion
) {
	let session = null;

	const experimentPromise = mw.testKitchen.getExperiment( experimentName )
		.then( ( exp ) => {
			const { isUserExcluded, exclusionReason } = getExclusion();
			return { exp, isUserExcluded, exclusionReason };
		} );

	experimentPromise.then( ( { exp, isUserExcluded, exclusionReason } ) => {
		if ( !exp.isAssignedGroup( 'control', treatmentGroupName ) ) {
			return;
		}

		// Do not modify the settings of any excluded user
		if ( isUserExcluded ) {
			if ( exclusionReason ) {
				exp.send( 'eligibility_check_fail', {
					action_context: { reason: exclusionReason },
					performer: { edit_count: mw.config.get( 'wgUserEditCount' ) }
				} );
			}
			return;
		}

		// The user is definitely enrolled in an existing experiment by this point.
		// Both groups get suggestion mode turned on and only the treatment group
		// gets the discoverability effects.
		mw.user.options.set( 'visualeditor-editcheck-suggestions', true );

		if ( exp.isAssignedGroup( treatmentGroupName ) ) {
			mw.user.options.set( 'visualeditor-editcheck-discoverability', true );
		}
	} );

	mw.hook( 've.newTarget' ).add( ( target ) => {
		const initTimestamp = mw.now();

		experimentPromise.then( ( { exp, isUserExcluded } ) => {
			if ( !exp.isAssignedGroup( 'control', treatmentGroupName ) ) {
				return;
			}

			// Skip experimentation for any excluded user
			if ( isUserExcluded ) {
				return;
			}

			const send = ( action, data ) => {
				data.funnel_entry_token = editingSessionService.getEditingSessionId( null, true );
				data.mediawiki = { database: mw.config.get( 'wgDBname' ) };
				data.action_context = data.action_context || {};
				data.action_context.interface = target.getDefaultMode() === 'source' ? 'wikitext-2017' : 'visualeditor';
				// This needs to be a string, but we've left it as an object until
				// now so it can be easily modified:
				data.action_context = JSON.stringify( data.action_context );
				exp.send( action, data );
			};
			let saved = false;
			let changed = false;
			let pendingActionsUpdatedListener = null;
			let currentSession = null;

			const timings = { init: initTimestamp };

			function clearSession() {
				saved = false;
				changed = false;
			}

			function cancelPendingSuggestionCheck() {
				if ( !pendingActionsUpdatedListener ) {
					return;
				}
				const controller = target.editcheckController;
				if ( controller ) {
					controller.off( 'actionsUpdated', pendingActionsUpdatedListener );
				}
				pendingActionsUpdatedListener = null;
			}

			// Handler for any time user clicks 'edit'
			function onSessionStart() {
				clearSession();
				cancelPendingSuggestionCheck();
				timings.ready = mw.now();

				currentSession = { send, resolveSuggestionAvailability: null };
				session = currentSession;

				const controller = target.editcheckController;
				if ( !controller ) {
					return;
				}

				let resolved = false;
				const resolveSuggestionAvailability = ( hadSuggestionInTime ) => {
					if ( resolved ) {
						return;
					}
					resolved = true;
					cancelPendingSuggestionCheck();
					currentSession.resolveSuggestionAvailability = null;

					const actionSource = ( target.section !== null && target.section !== undefined && target.section !== '0' ) ?
						'section' : 'page';

					// for either group, only log exposure when the onboarding dialog is/would be shown
					if ( hadSuggestionInTime ) {
						exp.sendExposure();
					}

					setTimeout( () => {
						send( 'editor_ready', {
							action_source: actionSource,
							page: {
								namespace_id: mw.config.get( 'wgNamespaceNumber' )
							},
							action_context: {
								had_suggestion_in_time: hadSuggestionInTime,
								timing_ms: timings.ready - timings.init
							}
						} );
					}, EXPOSURE_TO_READY_DELAY_MS );
				};
				currentSession.resolveSuggestionAvailability = resolveSuggestionAvailability;

				const onActionsUpdated = () => {
					if ( controller.getActions().some( ( action ) => action.isSuggestion() ) ) {
						resolveSuggestionAvailability( true );
					}
				};
				pendingActionsUpdatedListener = onActionsUpdated;
				controller.on( 'actionsUpdated', onActionsUpdated );
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
				cancelPendingSuggestionCheck();
				if ( session === currentSession ) {
					session = null;
				}
				// For this experiment we're ignoring 'preinit' or 'abandonMidsave' events
				// Also must exclude normal teardown that happens after successful save
				if ( target.activating || target.saving || saved ) {
					return;
				}
				const abortType = changed ? 'abandon' : 'nochange';

				timings.abandon = mw.now();
				send( 'abort', {
					action_subtype: abortType,
					action_context: { timing_ms: timings.abandon - timings.ready }
				} );
			} );
		} );
	} );

	function onVisualEditorFeatureUse( _, data ) {
		if ( !data || !session ) {
			return;
		}
		const send = session.send;
		if ( data.feature.startsWith( 'editCheck-' ) ) {
			// The suffix of the feature name is the type of suggestion,
			// e.g. 'editCheck-tone' -> 'tone'
			const suggestionType = data.feature.slice( 'editCheck-'.length );
			if ( data.action.startsWith( 'suggestion-seen-' ) ) {
				const moment = data.action.replace( 'suggestion-seen-', '' );
				send( 'suggestion_seen', {
					action_subtype: moment,
					action_context: { suggestion_type: suggestionType }
				} );
			}
			if ( data.action.startsWith( 'suggestion-shown-' ) ) {
				const moment = data.action.replace( 'suggestion-shown-', '' );
				setTimeout( () => {
					send( 'suggestion_shown', {
						action_subtype: moment,
						action_context: { suggestion_type: suggestionType }
					} );
				}, EXPOSURE_TO_READY_DELAY_MS );
			}
			if ( data.action.startsWith( 'suggestion-action-' ) ) {
				const choice = data.action.replace( 'suggestion-action-', '' );
				if ( ACCEPT_ACTIONS.includes( choice ) ) {
					send( 'suggestion_accept', {
						action_subtype: choice,
						action_context: { suggestion_type: suggestionType }
					} );
				} else if ( DECLINE_ACTIONS.includes( choice ) ) {
					send( 'suggestion_decline', {
						action_subtype: choice,
						action_context: { suggestion_type: suggestionType }
					} );
				}
			}
		} else if ( data.feature === 'editCheck' ) {
			// Welcome/onboarding dialog for suggestion mode discoverability
			if ( data.action === 'discoverability-deadline-reached' ) {
				if ( session.resolveSuggestionAvailability ) {
					session.resolveSuggestionAvailability( false );
				}
			} else if ( data.action === 'discoverability-welcome-shown' ) {
				setTimeout( () => {
					send( 'impression', {
						element_friendly_name: 'suggestion_mode_welcome_dialog'
					} );
				}, EXPOSURE_TO_READY_DELAY_MS );
			} else if ( data.action === 'discoverability-welcome-view' ) {
				send( 'click', {
					element_friendly_name: 'suggestion_mode_welcome_dialog_view_suggestions'
				} );
			} else if ( data.action === 'discoverability-welcome-nothanks' ) {
				send( 'click', {
					element_friendly_name: 'suggestion_mode_welcome_dialog_not_now'
				} );
			} else if ( data.action === 'discoverability-welcome-close' ) {
				send( 'click', {
					element_friendly_name: 'suggestion_mode_welcome_dialog_close'
				} );
			}
		} else if ( data.feature === 'editCheckGutterIcon' ) {
			// The suggestion lightbulb interventions on the sidebar
			if ( data.action.startsWith( 'click' ) ) {
				send( 'click', {
					element_friendly_name: 'suggestion_mode_lightbulb',
					action_context: { is_pulsing: data.action.includes( 'pulsing' ) }
				} );
			}
		} else if ( data.feature === 'editCheckScrollIntoView' ) {
			// The "View suggestions" button intervention
			if ( data.action === 'click-show' ) {
				send( 'click', {
					element_friendly_name: 'suggestion_mode_view_suggestions'
				} );
			}
		}
	}

	mw.trackSubscribe( 'visualEditorFeatureUse', onVisualEditorFeatureUse );
};
