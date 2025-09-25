const EXPERIMENT_NAME = 'we-3-3-4-reading-list-test1';
const ACTION_SAVE = 'save_article_to_reading_list';
const ACTION_REMOVE = 'remove_article_from_reading_list';

let experiment = null;
let experimentLoaded = false;

/**
 * Load the experiment once and cache it
 *
 * @return {Promise<Object|null>} The experiment object or null
 */
function loadExperiment() {
	if ( experimentLoaded ) {
		return Promise.resolve( experiment );
	}

	return mw.loader.using( 'ext.xLab' )
		.then( () => {
			try {
				experiment = mw.xLab.getExperiment( EXPERIMENT_NAME );
				experimentLoaded = true;
				return experiment;
			} catch ( error ) {
				mw.log( 'Error loading experiment:', error );
				return null;
			}
		} )
		.catch( ( error ) => {
			mw.log( 'Error loading xLab module:', error );
			return null;
		} );
}

/**
 * Track page visit event for the experiment
 */
function trackPageVisit() {
	loadExperiment().then( ( exp ) => {
		if ( exp && exp.assignedGroup ) {
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

			// Track clicks on reading list bookmark icon
			$( document ).find( '.mw-ui-icon-bookmarkList' ).on( 'click', () => {
				exp.send( 'click', {
					action_subtype: 'view_reading_list',
					action_source: 'top_right'
				} );
			} );
		}
	} );
}

/**
 * Track clicks on reading list link in notifications
 */
function trackNotificationClicks() {
	loadExperiment().then( ( exp ) => {
		if ( exp && exp.isAssignedGroup( 'treatment' ) ) {
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
	} );
}

/**
 * @param {number} size
 * @param {string} subtype
 * @param {string} source
 */
function recordExperimentEvent( size, subtype, source ) {
	loadExperiment().then( ( exp ) => {
		if ( exp && exp.assignedGroup ) {
			exp.send( 'click', {
				action_subtype: subtype,
				action_source: source,
				action_context: JSON.stringify( { article_count: size } )
			} );
		}
	} );
}

mw.hook( 'readingLists.bookmark.edit' ).add( ( isSaved, entryId, listPageCount, source ) => {
	recordExperimentEvent( listPageCount, isSaved ? ACTION_SAVE : ACTION_REMOVE, source );
} );

trackPageVisit();
trackNotificationClicks();
