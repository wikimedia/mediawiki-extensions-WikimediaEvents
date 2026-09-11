/*!
 * Tracks which presentation mode the reader is currently viewing a diff in, for the
 * diff-health-metrics instrument (T434795, T436254).
 *
 * The mode has to be read at event time rather than once at init, because both of its axes
 * change without a page load:
 *
 * - Inline vs two-column is switched by the core toggle in mediawiki.diff, which re-fetches
 *   the diff body through action=compare.
 * - Visual vs wikitext is switched by VisualEditor, which renders the visual diff client-side.
 *
 * Neither switch updates the server-rendered diff-type-* class on the diff table, so that
 * class is only trustworthy as the initial value.
 */

const MODE_VISUAL = 'visual';
const MODE_WIKITEXT_INLINE = 'wikitext_inline';
const MODE_WIKITEXT_TABLE = 'wikitext_table';

/** @type {{isInline: boolean, isVisual: boolean}|null} */
let state = null;

/** @type {Array<function(boolean): void>} */
const inlineListeners = [];

let inlineWidget = null;

/**
 * Read the initial mode. Done on first use rather than at module scope so that the DOM read
 * does not risk a forced style recalculation during module execution.
 *
 * @return {{isInline: boolean, isVisual: boolean}}
 */
function readInitialState() {
	return {
		// DifferenceEngine::addHeader() puts diff-type-inline or diff-type-table on the diff
		// table. Note that MinervaNeue overrides the user's preference to inline, so on
		// mobile this is always inline and the toggle is never rendered.
		isInline: !!document.querySelector( 'table.diff.diff-type-inline' ),

		// Mirrors the initial mode VisualEditor computes in ve.init.mw.DiffPage.init.js.
		// Duplicating the rule is unfortunate, but this axis is exposed neither as an
		// mw.config var nor as a DOM class, and VisualEditor fires no hook when it changes.
		isVisual: (
			mw.util.getParamValue( 'diffmode' ) ||
			mw.user.options.get( 'visualeditor-diffmode-historical' ) ||
			'source'
		) === 'visual'
	};
}

/**
 * @return {{isInline: boolean, isVisual: boolean}}
 */
function getState() {
	if ( state === null ) {
		state = readInitialState();
	}

	return state;
}

// Fired by mediawiki.diff when the inline toggle is rendered, with the OOUI widget itself.
// The hook is memorised, so guard against binding twice to the same widget.
mw.hook( 'wikipage.diff.diffTypeSwitch' ).add( ( widget ) => {
	if ( widget === inlineWidget ) {
		return;
	}

	inlineWidget = widget;
	getState().isInline = widget.getValue();

	widget.on( 'change', ( value ) => {
		// Listeners run before the state is updated, so that an event they send records the
		// mode the reader was leaving rather than the one they arrived at.
		inlineListeners.forEach( ( listener ) => listener( value ) );
		getState().isInline = value;
	} );
} );

module.exports = {
	MODE_VISUAL: MODE_VISUAL,
	MODE_WIKITEXT_INLINE: MODE_WIKITEXT_INLINE,
	MODE_WIKITEXT_TABLE: MODE_WIKITEXT_TABLE,

	/**
	 * @return {string} One of the MODE_* values
	 */
	get: function () {
		if ( getState().isVisual ) {
			return MODE_VISUAL;
		}

		return getState().isInline ? MODE_WIKITEXT_INLINE : MODE_WIKITEXT_TABLE;
	},

	/**
	 * @return {boolean} Whether the wikitext diff is currently shown in one column
	 */
	isInline: function () {
		return getState().isInline;
	},

	/**
	 * Record that the reader has switched between the visual and wikitext diffs.
	 *
	 * @param {boolean} value
	 */
	setVisual: function ( value ) {
		getState().isVisual = value;
	},

	/**
	 * @param {function(boolean): void} listener Called with the state the inline toggle is
	 *  being switched to, before get() starts reporting it
	 */
	onInlineChange: function ( listener ) {
		inlineListeners.push( listener );
	}
};

// Exposed for unit testing.
if ( window.QUnit ) {
	module.exports.reset = function () {
		state = null;
		inlineWidget = null;
		inlineListeners.length = 0;
	};
}
