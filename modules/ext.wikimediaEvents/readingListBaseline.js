const INSTRUMENT_NAME = 'reading-list-engagement';
const ACTION_SAVE = 'save_article_to_reading_list';
const ACTION_REMOVE = 'remove_article_from_reading_list';

const instrumentPromise = mw.loader.using( 'ext.testKitchen' )
	.then( () => {
		try {
			const instrument = mw.testKitchen.getInstrument( INSTRUMENT_NAME );
			return instrument;
		} catch ( error ) {
			return null;
		}
	} )
	.catch( ( error ) => {
		mw.log( 'Error loading ext.testKitchen module:', error );
		return null;
	} );

/**
 * Track clicks on reading list bookmark icon
 *
 * @param {jQuery} $bookmarkButton The bookmark button element
 * @param {Object} instrument The instrument object
 */
function trackBookmarkIconButtonClicks( $bookmarkButton, instrument ) {
	$bookmarkButton.on( 'click', ( event ) => {
		const isToolbarButton = event.target.closest( '#pt-readinglists-2' ) !== null;
		instrument.send( 'click', {
			action_subtype: 'view_reading_list',
			action_source: isToolbarButton ? 'top_right' : 'user_menu'
		} );
	} );
}

/**
 * Track clicks on reading list link in notifications
 *
 * @param {Object} instrument The instrument object
 */
function trackNotificationClicks( instrument ) {
	document.addEventListener( 'click', ( event ) => {
		const $link = $( event.target ).closest( 'a' );

		// Check if it's a reading list link inside a notification
		if (
			$link.length &&
			$link.attr( 'href' ) &&
			$link.attr( 'href' ).includes( 'Special:ReadingLists' ) &&
			$link.closest( '.mw-notification' ).length
		) {

			instrument.send( 'click', {
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
 * @param {Object} instrument The instrument object
 */
function trackReadingListLinkClicks( instrument ) {
	document.addEventListener( 'click', ( event ) => {
		const $link = $( event.target ).closest( 'a' );
		const $grid = $link.closest( '.reading-lists-grid' );

		if (
			$link.length &&
			$grid.length
		) {
			instrument.send( 'click', {
				action_subtype: 'view_article',
				action_source: 'reading_list'
			} );
		}
	} );
}

/**
 * @param {Object} instrument The instrument object
 * @param {string} subtype
 * @param {string} source
 */
function recordInstrumentEvent( instrument, subtype, source ) {
	instrument.send( 'click', {
		action_subtype: subtype,
		action_source: source
	} );
}

$( () => {

	instrumentPromise.then( ( instrument ) => {
		if ( !instrument ) {
			return;
		}

		const $bookmarkButton = $( document ).find( '.mw-ui-icon-bookmarkList, .menu__item--readinglists, #pt-readinglists a' );

		// Only run on the reading lists special page
		if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'ReadingLists' ) {
			trackReadingListLinkClicks( instrument );
		}

		if ( $bookmarkButton.length ) {
			trackBookmarkIconButtonClicks( $bookmarkButton, instrument );
			trackNotificationClicks( instrument );
		}

		mw.hook( 'readingLists.bookmark.edit' ).add( ( isSaved, entryId, listPageCount, source ) => {
			const action = isSaved ? ACTION_SAVE : ACTION_REMOVE;
			recordInstrumentEvent( instrument, action, source );
		} );
	} );
} );
