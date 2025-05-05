const { logInteraction } = require( './summariesInteractions.js' );

/**
 * Helper function to determine whether the eligible user has enabled summaries
 * via the call to action using client prefs
 *
 * @return {boolean}
 */
function hasOptedInToSummaries() {
	return mw.user.clientPrefs.get( 'ext-summaries' ) === '1';
}

let instrumentationEnabled = false;
/**
 * Begin tracking user interactions with the summaries pilot feature for later
 * metric analysis
 *
 * Note that in addition to adding hook handlers for logging frontend events,
 * summary availability is determined server-side so we must query the DOM.  At
 * minimum this is acceptable for a mobile pilot
 *
 * @param {Function} injectedLogInteraction - optional function to use for testing
 */
function setupInstrumentation( injectedLogInteraction ) {
	const log = logInteraction || injectedLogInteraction;
	if ( instrumentationEnabled ) {
		return;
	}
	// a summary is available on the page
	if ( document.querySelector( '.ext-article-summaries-container' ) ) {
		log( 'init', {
			action_subtype: 'article_summary_link'
		} );
	}

	// the read summary button is clicked
	mw.hook( 'ext.articleSummaries.summary.opened' ).add( () => {
		log( 'click', {
			action_subtype: 'read_summary',
			action_source: 'article'
		} );
	} );

	// the summary overlay is shown
	mw.hook( 'ext.articleSummaries.summary.shown' ).add( () => {
		log( 'show', {
			action_subtype: 'show_article_summary'
		} );
	} );

	// the yes button is pressed
	mw.hook( 'ext.articleSummaries.summary.yesButton' ).add( () => {
		log( 'click', {
			action_subtype: 'yes',
			action_context: 'summaryHelpfulnessCheck',
			action_source: 'article_summary'
		} );
	} );

	// the no button is pressed
	mw.hook( 'ext.articleSummaries.summary.noButton' ).add( () => {
		log( 'click', {
			action_subtype: 'no',
			action_context: 'summaryHelpfulnessCheck',
			action_source: 'article_summary'
		} );
	} );

	instrumentationEnabled = true;
}

// only enable intrumentation if the user has opted into sumaries and we're not
// in a test environment
if ( !window.QUnit && hasOptedInToSummaries() ) {
	setupInstrumentation();
}
mw.hook( 'ext.articleSummaries.init' ).add( setupInstrumentation );

module.exports = {
	test: {
		hasOptedInToSummaries,
		setupInstrumentation
	}
};
