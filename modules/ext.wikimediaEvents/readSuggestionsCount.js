// TEMPORARY: A/B/C test of the edit suggestion indicator in read mode (T430712).
// ReadSuggestionsCountExperimentHooks.php shows the indicator.
const setUpSuggestionDiscoverabilityExperiment = require( './suggestionDiscoverability.js' );
const getSuggestionExperimentExclusion = require( './suggestionEligibility.js' );

// The edit button that shows the indicator, for each skin in the experiment.
// ReadSuggestionsCountExperimentHooks.php has the same skins. Keep them the same.
// Other skins show the indicator in a tab, but not for the control group, so the
// exposure cannot be the same for all groups.
const INDICATOR_BUTTONS = {
	// Visible only when the sticky header shows
	'vector-2022': '#ca-ve-edit-sticky-header',
	// Only one of these exists
	minerva: '#page-actions-ve-edit, #page-actions-edit'
};

// Time that the button must be fully visible before it counts as seen
const MIN_VISIBLE_MS = 500;

// If the page loads directly into the editor, the user does not see the read view first.
// This module runs on page load, so the URL is still the URL of the page load.
const loadedIntoEditor = mw.config.get( 'wgAction' ) === 'edit' ||
	new URLSearchParams( location.search ).has( 'veaction' ) ||
	location.hash.startsWith( '#/editor' );
let openedOnLoad = loadedIntoEditor;

let isIndicatorSeen = false;

// The editor covers the read view. IntersectionObserver does not detect this on mobile.
// On mobile, all overlays, e.g. the editor and search, have a '#/' URL hash. The hash also
// covers the time before VisualEditor makes the target, and the mobile source editor.
let isEditorOpen = false;
const editorListeners = [];
function setEditorOpen( isOpen ) {
	isEditorOpen = isOpen;
	editorListeners.forEach( ( listener ) => listener() );
}
mw.hook( 've.newTarget' ).add( ( target ) => {
	if ( target.constructor.static.name !== 'article' ) {
		return;
	}
	setEditorOpen( true );
	target.once( 'teardown', () => setEditorOpen( false ) );
} );

/**
 * Wait until the user sees the indicator button in the read view. For the control
 * group, the button has no indicator, but the rule is the same.
 *
 * @ignore
 * @return {Promise} Resolves when the button is seen. Does not resolve if the page
 *  has no suggestions.
 */
function whenIndicatorSeen() {
	return new Promise( ( resolve ) => {
		const readSuggestions = mw.config.get( 'wgVisualEditorEditCheckReadSuggestions' );
		const button = document.querySelector( INDICATOR_BUTTONS[ mw.config.get( 'skin' ) ] );
		if ( !readSuggestions || !readSuggestions.count || !button ) {
			return;
		}

		let isVisible = false;
		let timer = null;
		let observer = null;

		function update() {
			clearTimeout( timer );
			if (
				isVisible && !isEditorOpen && !location.hash.startsWith( '#/' ) &&
				document.visibilityState === 'visible'
			) {
				timer = setTimeout( () => {
					observer.disconnect();
					document.removeEventListener( 'visibilitychange', update );
					window.removeEventListener( 'hashchange', update );
					editorListeners.splice( editorListeners.indexOf( update ), 1 );
					isIndicatorSeen = true;
					resolve();
				}, MIN_VISIBLE_MS );
			}
		}

		observer = new IntersectionObserver( ( entries ) => {
			isVisible = entries[ entries.length - 1 ].isIntersecting;
			update();
		}, { threshold: 1 } );
		observer.observe( button );
		document.addEventListener( 'visibilitychange', update );
		window.addEventListener( 'hashchange', update );
		editorListeners.push( update );
	} );
}

/**
 * Mark control group users who would see the welcome dialog now, if they were in a
 * treatment group. Eligibility then ignores their edit count, as for treatment group
 * users who saw the dialog.
 *
 * @ignore
 * @param {mw.testKitchen.ExperimentInterface} exp
 * @param {Object} target ve.init.mw.ArticleTarget
 */
function markWelcomeSeenForControl( exp, target ) {
	const { WELCOME_PREF, WELCOME_SEEN_CONTROL } = getSuggestionExperimentExclusion;
	// The treatment groups see the dialog only after the exposure, in the visual editor,
	// and only one time
	if (
		!exp.isAssignedGroup( 'control' ) ||
		!isIndicatorSeen ||
		target.getSurface().getMode() !== 'visual' ||
		mw.user.options.get( WELCOME_PREF ) !== null
	) {
		return;
	}
	mw.user.options.set( WELCOME_PREF, WELCOME_SEEN_CONTROL );
	mw.loader.using( 'mediawiki.api' ).then(
		() => new mw.Api().saveOption( WELCOME_PREF, WELCOME_SEEN_CONTROL )
	);
}

/**
 * Log the read mode count with the count in the editor, to find mismatches (T432288).
 * The counts can differ, e.g. after a template or check config changes.
 *
 * @ignore
 * @param {number} suggestionCount Suggestions in the editor
 * @param {Function} send
 * @param {Object} target ve.init.mw.ArticleTarget
 */
function logSuggestionCounts( suggestionCount, send, target ) {
	// Null if the server did not look up the count
	const readSuggestions = mw.config.get( 'wgVisualEditorEditCheckReadSuggestions' );
	// Only the first session can be the editor that the page loaded into
	const openedFromReadView = !openedOnLoad;
	openedOnLoad = false;
	send( 'suggestions_loaded', {
		action_context: {
			opened_from_read_view: openedFromReadView,
			read_suggestion_count: readSuggestions ? readSuggestions.count : null,
			read_indicator: readSuggestions ? readSuggestions.indicator : null,
			read_revision_id: mw.config.get( 'wgRevisionId' ),
			edit_suggestion_count: suggestionCount,
			edit_revision_id: target.revid
		}
	} );
}

setUpSuggestionDiscoverabilityExperiment(
	'editcheck-read-suggestions-count',
	[ 'lightbulb', 'count' ],
	() => {
		if ( !Object.prototype.hasOwnProperty.call( INDICATOR_BUTTONS, mw.config.get( 'skin' ) ) ) {
			return { isUserExcluded: true, exclusionReason: null };
		}
		return getSuggestionExperimentExclusion( { ignoreEditCountIfWelcomeSeen: true } );
	},
	{
		whenExposed: whenIndicatorSeen,
		onSuggestionInTime: markWelcomeSeenForControl,
		onInitialSuggestionsLoaded: logSuggestionCounts
	}
);
