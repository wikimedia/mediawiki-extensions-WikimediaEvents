// The experiment name is also used in the ReadingLists extension in the HookHandler class.
// We must keep the experiment names the same in both extensions.
const EXPERIMENT_NAME = mw.config.get( 'wgDBname' ) === 'enwiki' ?
	'we-3-3-4-reading-list-test1-en' :
	'we-3-3-4-reading-list-test1';
const STREAM_NAME = 'mediawiki.product_metrics.reading_list';
const ACTION_SAVE = 'save_article_to_reading_list';
const ACTION_REMOVE = 'remove_article_from_reading_list';

const experimentPromise = mw.loader.using( 'ext.xLab' )
	.then( () => {
		// need try/catch for jquery error when user is logged out and experiment.setStream fails
		try {
			const experiment = mw.xLab.getExperiment( EXPERIMENT_NAME );
			experiment.setStream( STREAM_NAME );
			return experiment;
		} catch ( error ) {
			// User not enrolled in experiment
			return null;
		}
	} )
	.catch( ( error ) => {
		mw.log( 'Error loading ext.xLab module:', error );
		return null;
	} );

/**
 * Track page visit event for the experiment
 *
 * @param {Object} exp The experiment object
 */
function trackPageVisit( exp ) {
	// Determine if referrer is internal or external
	let actionSource = 'external_referer';
	if ( document.referrer ) {
		const referrerUrl = new URL( document.referrer );
		const currentUrl = new URL( document.location.href );
		actionSource = referrerUrl.hostname === currentUrl.hostname ? 'internal_referer' : 'external_referer';
	}
	exp.send( 'page_load', {
		action_source: actionSource,
		action_context: document.location.pathname
	} );
}

/**
 * Track clicks on reading list bookmark icon
 *
 * @param {jQuery} $bookmarkButton The bookmark button element
 * @param {Object} exp The experiment object
 */
function trackBookmarkIconButtonClicks( $bookmarkButton, exp ) {
	$bookmarkButton.on( 'click', () => {
		exp.send( 'click', {
			action_subtype: 'view_reading_list',
			action_source: 'top_right'
		} );
	} );
}

/**
 * Track clicks on reading list link in notifications
 *
 * @param {Object} exp The experiment object
 */
function trackNotificationClicks( exp ) {
	document.addEventListener( 'click', ( event ) => {
		const $link = $( event.target ).closest( 'a' );

		// Check if it's a reading list link inside a notification
		if (
			$link.length &&
			$link.attr( 'href' ) &&
			$link.attr( 'href' ).includes( 'Special:ReadingLists' ) &&
			$link.closest( '.mw-notification' ).length
		) {

			exp.send( 'click', {
				action_subtype: 'view_reading_list',
				action_source: 'article_saved_popup'
			} );
		}
	}, true ); // Use capture phase (true) so this runs before stopPropagation
	// within mw.notification.notify is called
}

/**
 * Track clicks to saved articles from the reading list special page
 *
 * @param {Object} exp The experiment object
 */
function trackReadingListLinkClicks( exp ) {
	document.addEventListener( 'click', ( event ) => {
		const $link = $( event.target ).closest( 'a' );
		const $grid = $link.closest( '.reading-lists-grid' );

		if (
			$link.length &&
			$grid.length
		) {
			const articleCount = $grid.find( 'a' ).length;

			exp.send( 'click', {
				action_subtype: 'view_article',
				action_source: 'reading_list',
				action_context: JSON.stringify( { article_count: articleCount } )
			} );
		}
	} );
}

/**
 * @param {Object} exp The experiment object
 * @param {number} size
 * @param {string} subtype
 * @param {string} source
 */
function recordExperimentEvent( exp, size, subtype, source ) {
	exp.send( 'click', {
		action_subtype: subtype,
		action_source: source,
		action_context: JSON.stringify( { article_count: size } )
	} );
}

$( () => {

	experimentPromise.then( ( exp ) => {
		if ( !exp ) {
			return;
		}

		const experimentGroup = exp.getAssignedGroup();
		if ( experimentGroup !== 'control' && experimentGroup !== 'treatment' ) {
			return;
		}

		const $bookmarkButton = $( document ).find( '.mw-ui-icon-bookmarkList' );

		trackPageVisit( exp );

		// Only run on the reading lists special page
		if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'ReadingLists' ) {
			trackReadingListLinkClicks( exp );
		}

		if ( $bookmarkButton.length ) {
			trackBookmarkIconButtonClicks( $bookmarkButton, exp );
			trackNotificationClicks( exp );
		}

		mw.hook( 'readingLists.bookmark.edit' ).add( ( isSaved, entryId, listPageCount, source ) => {
			const action = isSaved ? ACTION_SAVE : ACTION_REMOVE;
			recordExperimentEvent( exp, listPageCount, action, source );
		} );
	} );
} );
