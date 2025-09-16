/**
 * Synthetic A/A test:
 * An experiment-specific instrument that sends a "page-visited" event when users
 * land on MinT for Wiki Readers for the "fy25-26-we-3-1-5-mint-readers" experiment.
 *
 * See https://phabricator.wikimedia.org/T397600 for more context.
 */

const EXPERIMENT_NAME = 'fy25-26-we-3-1-5-mint-readers';
const SCHEMA_ID = '/analytics/product_metrics/web/translation/1.4.2';
const STREAM_ID = 'mediawiki.product_metrics.translation_mint_for_readers.experiments';

/**
 * Check if user is currently on a mobile Wikipedia article page
 * namespace 0, not main page, viewing action, latest revision, and mobile mode
 */
function isOnMobileArticlePage() {
	return mw.config.get( 'wgNamespaceNumber' ) === 0 &&
		!mw.config.get( 'wgIsMainPage' ) &&
		mw.config.get( 'wgAction' ) === 'view' &&
		mw.config.get( 'wgRevisionId' ) === mw.config.get( 'wgCurRevisionId' ) &&
		mw.config.get( 'wgMFMode' );
}

// Enabled wikis can be followed here
// this is for the experiment and not enable for them to visit MinT for Wiki Readers.
// https://phabricator.wikimedia.org/T388402
function isMinTForWikireadersExperimentEnabled() {
	const code = mw.config.get( 'wgContentLanguage' );
	const targetLanguages = [ 'ko', 'th', 'el', 'is', 'si', 'km', 'azb', 'ig', 'min', 'bcl', 'am', 'ff', 'fon' ];
	return targetLanguages.includes( code );
}

if ( isMinTForWikireadersExperimentEnabled() && isOnMobileArticlePage() ) {
	// Hook ensures page content is fully loaded
	mw.hook( 'wikipage.content' ).add( () => {
		mw.loader.using( 'ext.xLab' ).then( () => {
			const experiment = mw.xLab.getExperiment( EXPERIMENT_NAME );

			experiment.setSchema( SCHEMA_ID );
			experiment.setStream( STREAM_ID );

			experiment.send( 'page_visited', {
				instrument_name: 'PageVisit',
				translation: {}
			} );
		} );
	} );
}
