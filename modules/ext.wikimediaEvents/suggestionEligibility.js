const WELCOME_PREF = 'visualeditor-editcheck-showdiscoverabilitywelcome';
// VisualEditor sets the preference to '0' when it shows the welcome dialog
const WELCOME_SEEN = '0';
// readSuggestionsCount.js sets this for control group users who would see the welcome dialog.
// VisualEditor shows the dialog for any value that is not '0'.
const WELCOME_SEEN_CONTROL = '2';

/**
 * Find if the user is excluded from the suggestion mode experiments: they are only for
 * logged-in users with <= 150 edits who do not already have suggestion mode enabled.
 *
 * ReadSuggestionsCountExperimentHooks.php has the same rules. Keep them the same.
 *
 * @ignore
 * @param {Object} [options]
 * @param {boolean} [options.ignoreEditCountIfWelcomeSeen=false] Do not exclude users who
 *  saw the suggestion mode welcome dialog, or would see it in the control group, for their
 *  edit count
 * @return {{isUserExcluded: boolean, exclusionReason: string|null}}
 */
function getSuggestionExperimentExclusion( { ignoreEditCountIfWelcomeSeen = false } = {} ) {
	const isWelcomeSeen = [ WELCOME_SEEN, WELCOME_SEEN_CONTROL ]
		.includes( String( mw.user.options.get( WELCOME_PREF ) ) );
	let exclusionReason = null;
	if ( +mw.user.options.get( 'visualeditor-editcheck-suggestions' ) ) {
		exclusionReason = 'suggestion_mode_enabled';
	} else if (
		mw.user.isNamed() && mw.config.get( 'wgUserEditCount' ) > 150 &&
		!( ignoreEditCountIfWelcomeSeen && isWelcomeSeen )
	) {
		exclusionReason = 'edit_count';
	}

	const isUserExcluded = (
		exclusionReason !== null ||
		!mw.user.isNamed()
	);

	return { isUserExcluded, exclusionReason };
}

module.exports = getSuggestionExperimentExclusion;
module.exports.WELCOME_PREF = WELCOME_PREF;
module.exports.WELCOME_SEEN_CONTROL = WELCOME_SEEN_CONTROL;
