/* eslint-disable camelcase */
const INSTRUMENT_NAME = 'reading-list-engagement';
const ACTION_SAVE = 'save_article_to_reading_list';
const ACTION_REMOVE = 'remove_article_from_reading_list';
// Minerva omits the ID so we require a separate selector for that.
const RL_MENU_SELECTOR = '#pt-readinglists-2, #pt-readinglists, #pt-readinglists-sticky-header, .menu__item--readinglists';
const SOURCE_USER_MENU = 'user_menu'; // corresponds to dropdown
const SOURCE_USER_MENU_OVERFLOW = 'top_right'; // corresponds to p-vector-user-menu-overflow
const SOURCE_STICKY_HEADER = 'sticky_header';

/**
 * @param {Event} event
 * @param {string} selector
 * @return {Element|null}
 */
function findClosestElementFromEvent( event, selector ) {
	if ( !event.target ) {
		return null;
	}
	const target = /** @type Element */(
		event.target
	);
	return target.closest( selector );
}

/**
 * Track clicks on reading list bookmark icon
 *
 * @param {Object} instrument The instrument object
 */
function trackBookmarkListIconButtonClicks( instrument ) {
	document.body.addEventListener( 'click', ( /** @type Event */event ) => {
		const link = findClosestElementFromEvent( event, RL_MENU_SELECTOR );
		if ( !link ) {
			return;
		}

		const isStickyHeader = findClosestElementFromEvent( event, '#ca-bookmark-sticky-header' );
		const isToolbarButton = findClosestElementFromEvent( event, '#pt-readinglists-2' ) !== null;
		const userMenuSource = isToolbarButton ? SOURCE_USER_MENU_OVERFLOW : SOURCE_USER_MENU;
		const action_source = isStickyHeader ? SOURCE_STICKY_HEADER : userMenuSource;
		recordInstrumentEvent( instrument, 'view_reading_list', action_source );
	}, true );
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
		const $items = $link.closest( '.reading-lists-items' );

		if (
			$link.length &&
			$items.length
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

const init = ( instrument ) => {
	// Only run on the reading lists special page
	if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'ReadingLists' ) {
		trackReadingListLinkClicks( instrument );
	}

	// Note sticky header may be added via JavaScript, and is only present in selector for testing.
	// Checking the other selectors should be sufficient for detecting the reading list feature.
	const bookmarkButtons = document.querySelectorAll( RL_MENU_SELECTOR );
	if ( bookmarkButtons.length ) {
		trackBookmarkListIconButtonClicks( instrument );
		trackNotificationClicks( instrument );
	}

	mw.hook( 'readingLists.bookmark.change' ).add( ( isSaved, source ) => {
		const action = isSaved ? ACTION_SAVE : ACTION_REMOVE;
		recordInstrumentEvent( instrument, action, source );
	} );
};

$( () => {
	if ( window.QUnit ) {
		return;
	}
	init( mw.testKitchen.getInstrument( INSTRUMENT_NAME ) );
} );

module.exports = {
	init,
	trackBookmarkListIconButtonClicks
};
