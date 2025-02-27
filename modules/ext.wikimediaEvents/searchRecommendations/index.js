const { logInteraction } = require( './webABTestInteractions.js' );
const SessionLengthInstrumentMixin = require( '../sessionLength/mixin.js' );

let searchActivityId;
let firstQuery = false;
function resetSearchActivity() {
	searchActivityId = randomToken();
	firstQuery = false;
}

/**
 * Generate a unique token. Appends timestamp in base 36 to increase
 * uniqueness of the token.
 *
 * @return {string}
 */
function randomToken() {
	return mw.user.generateRandomSessionId() + Date.now().toString( 36 );
}

function hasExistingSessionTick() {
	const KEY_COUNT = 'mp-sessionTickTickCount';
	const existingSessionTick = Number( mw.storage.session.get( KEY_COUNT ) );
	return !!existingSessionTick;
}

function setupInstrumentation( { group, experimentName } ) {
	// This name must be synced with RelatedArticles extension.json
	// We use indexOf as on beta cluster we suffix with beta cluster.
	if ( experimentName.indexOf( 'RelatedArticles test' ) > -1 ) {
		const experimentData = {
			experiments: {
				enrolled: [ experimentName ],
				assigned: {
					[ experimentName ]: group
				}
			}
		};

		/**
		 * Expands data with shared data.
		 *
		 * @param {Object} data
		 * @return {Object}
		 */
		const makeInstrumentationData = ( data ) => Object.assign( {
			funnel_name: 'Search related articles',
			funnel_entry_token: searchActivityId
		}, data, experimentData );

		// If the session tick has already started when
		// the overlay was opened, resume logging session length on pageload.
		if ( hasExistingSessionTick() ) {
			SessionLengthInstrumentMixin.start( 'product_metrics.web_base.search_ab_test_session_ticks', '/analytics/product_metrics/web/base/1.3.0', experimentData );
		}

		// The users clicked the empty search box
		// The search overlay was opened.
		mw.hook( 'ext.MobileFrontend.searchOverlay.open' ).add( () => {
			resetSearchActivity();
			logInteraction(
				'click',
				makeInstrumentationData( {
					action_source: 'search_box'
				} )
			);

			if ( !hasExistingSessionTick() ) {
				SessionLengthInstrumentMixin.start( 'product_metrics.web_base.search_ab_test_session_ticks', '/analytics/product_metrics/web/base/1.3.0', experimentData );
			}
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
