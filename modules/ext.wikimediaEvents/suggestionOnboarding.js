const setUpSuggestionDiscoverabilityExperiment = require( './suggestionDiscoverability.js' );

setUpSuggestionDiscoverabilityExperiment( 'suggestion-mode-onboarding', 'suggestions-onboarding', () => {
	// Only include logged-in mobile users with <= 150 edits
	// who do not already have suggestion mode enabled.
	let exclusionReason = null;
	if ( +mw.user.options.get( 'visualeditor-editcheck-suggestions' ) ) {
		exclusionReason = 'suggestion_mode_enabled';
	} else if ( mw.user.isNamed() && mw.config.get( 'wgUserEditCount' ) > 150 ) {
		exclusionReason = 'edit_count';
	}

	const isUserExcluded = (
		exclusionReason !== null ||
		!mw.user.isNamed() ||
		!mw.config.get( 'wgMFMode' )
	);

	return { isUserExcluded, exclusionReason };
} );
