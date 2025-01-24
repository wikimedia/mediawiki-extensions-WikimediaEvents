/* eslint-disable camelcase */
const { logInteraction } = require( './webABTestInteractions.js' );
const SessionLengthInstrumentMixin = require( '../sessionLength/mixin.js' );

let firstQuery = false;
function resetSearchSession() {
	firstQuery = false;
}

function setupInstrumentation( { group, experimentName } ) {
	// This name must be synced with RelatedArticles extension.json
	// We use indexOf as on beta cluster we suffix with beta cluster.
	if ( experimentName.indexOf( 'RelatedArticles test' ) > -1 ) {
		// This should be defined by ext.relatedArticles.readMore.bootstrap/index.js
		// If it doesn't exist, RelatedArticles for some reason didn't store a token so fall back
		// to sessionId.
		// @todo: Clarify with Jennifer and Bernard whether this should be replaced by T383936
		const funnelEntryToken = mw.storage.get( 'relatedArticles.token' ) || mw.user.sessionId();

		/**
		 * Expands data with shared data.
		 *
		 * @param {Object} data
		 * @return {Object}
		 */
		const makeInstrumentationData = ( data ) => Object.assign( {
			funnel_name: 'Search related articles',
			funnel_entry_token: funnelEntryToken,
			experiments: {
				enrolled: [ experimentName ],
				assigned: {
					[ experimentName ]: group
				}
			}
		}, data );

		// Start session length tracking.
		SessionLengthInstrumentMixin.start( 'searchRecommendationsStream', 'searchRecommendationsSchema' );

		// Stop tracking when the session ends.
		window.addEventListener( 'beforeunload', () => {
			SessionLengthInstrumentMixin.stop( 'searchRecommendationsStream' );
		} );

		// Search sessions initiations
		// e.g. the experiment is running
		// This event fires for both groups regardless of whether empty search shows or not.
		logInteraction(
			'init',
			makeInstrumentationData( {
				action_subtype: 'init_search_box'
			} )
		);

		// The users clicked the empty search box
		// The search overlay was opened.
		mw.hook( 'ext.MobileFrontend.searchOverlay.open' ).add( () => {
			resetSearchSession();
			logInteraction(
				'click',
				makeInstrumentationData( {
					action_source: 'search_box'
				} )
			);
		} );

		// The list of the empty-state recommendations appears
		// Usually occurs at exactly same time as ext.MobileFrontend.searchOverlay.open
		mw.hook( 'ext.MobileFrontend.searchOverlay.empty' ).add( () => {
			logInteraction(
				'show',
				makeInstrumentationData( {
					action_subtype: 'show_empty_state_recommendation'
				} )
			);
		} );

		// Actions of starting the first query that clears empty search recommendations
		mw.hook( 'ext.MobileFrontend.searchOverlay.startQuery' ).add( () => {
			// Only log on first query (as this clears search recommendations)
			if ( !firstQuery ) {
				logInteraction(
					'type',
					makeInstrumentationData( {
						action_source: 'search_box'
					} )
				);
				firstQuery = true;
			}
		} );

		// Impressions of the type-ahead search recommendations
		mw.hook( 'ext.MobileFrontend.searchOverlay.displayResults' ).add( () => {
			logInteraction(
				'show',
				makeInstrumentationData( {
					action_subtype: 'show_autocomplete_recommendation'
				} )
			);
		} );

		// Action of clicking the empty-state recomendations
		mw.hook( 'ext.relatedArticles.click' ).add( ( clickEventName ) => {
			// only watch clicks to empty search recommendations (not footer recommendations)
			if ( clickEventName === 'relatedArticles.emptySearch' ) {
				logInteraction(
					'click',
					makeInstrumentationData( {
						action_source: 'empty_search_suggestion'
					} )
				);
			}
		} );

		// Action of clicking the type-ahead search recomendations
		mw.hook( 'ext.MobileFrontend.searchOverlay.click' ).add( () => {
			logInteraction(
				'click',
				makeInstrumentationData( {
					action_source: 'autocomplete_search_suggestion'
				} )
			);
		} );
	}
}

// instrumentation is setup only if the A/B test has been initiated.
if (
	// @ts-ignore
	!window.QUnit
) {
	mw.hook( 'mediawiki.web_AB_test_enrollment' ).add( setupInstrumentation );
}

module.exports = {
	test: {
		setupInstrumentation
	}
};
