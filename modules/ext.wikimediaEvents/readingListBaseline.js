/* eslint-disable camelcase */
const INSTRUMENT_NAME = 'reading-list-engagement';
const ACTION_SAVE = 'save_article_to_reading_list';
const ACTION_REMOVE = 'remove_article_from_reading_list';
// Minerva omits the ID so we require a separate selector for that.
const RL_MENU_SELECTOR = '#pt-readinglists-2, #pt-readinglists, #pt-readinglists-sticky-header, .menu__item--readinglists';
const SOURCE_USER_MENU = 'user_menu'; // corresponds to dropdown
const SOURCE_USER_MENU_OVERFLOW = 'top_right'; // corresponds to p-vector-user-menu-overflow
const SOURCE_STICKY_HEADER = 'sticky_header';
const SOURCE_MW_NOTIFY_NOTIFICATION = 'article_saved_popup';
const SOURCE_READING_LIST_FEED = 'reading_list';
const SOURCE_READING_LIST_CUSTOM_LIST = 'reading_list_custom_list';
const SOURCE_ARTICLE = 'article';

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
		const link = findClosestElementFromEvent( event, 'a' );
		const linkHref = link ? link.getAttribute( 'href' ) : '';
		// Check if it's a reading list link inside a notification
		if (
			link &&
			linkHref &&
			linkHref.includes( 'Special:ReadingLists' ) &&
			link.closest( '.mw-notification' )
		) {
			recordInstrumentEvent( instrument, 'view_reading_list', SOURCE_MW_NOTIFY_NOTIFICATION );
		}
	}, true ); // Use capture phase (true) so this runs before stopPropagation
	// within mw.notification.notify is called
}

/**
 * @param {string} path
 * @return {boolean}
 */
const isOnCollectionPage = ( path ) => {
	/**
	 * @param {string} str
	 * @return {boolean}
	*/
	const isInt = ( str ) => !!str.match( /^[0-9]*$/ ) && !Number.isNaN( parseInt( str, 10 ) );
	const pathPrefix = mw.config.get( 'wgArticlePath' ).replace( '$1', '' );
	const rlPath = path.indexOf( pathPrefix ) === 0 ? path.slice( pathPrefix.length ) : path;
	// Remove any empty segments e.g. // or trailing "/"
	const segments = rlPath.split( '/' ).filter( ( str ) => str );
	// Given URLs can't be considered stable, its a reading list (feed) if:
	// Special:ReadingLists/<username> OR Special:ReadingLists/
	// It's reading_list_custom if:
	// Special:ReadingLists/<username>/<id> OR Special:ReadingLists/<username>/<id>/<name>
	// OR Special:ReadingLists/<id> OR Special:ReadingLists/<id>/<name>
	return segments.length > 3 && isInt( segments[ 2 ] ) ||
		segments.length === 3 && isInt( segments[ 1 ] ) ||
		segments.length === 3 && isInt( segments[ 2 ] ) ||
		segments.length === 2 && isInt( segments[ 1 ] );
};

/**
 * Check whether the user is currently on the ReadingLists page.
 *
 * @return {boolean}
 */

const isReadingListPage = () => mw.config.get( 'wgCanonicalSpecialPageName' ) === 'ReadingLists';
/**
 * @param {string} path
 * @return {string}
 */
const getSource = ( path ) => {
	if ( isReadingListPage() ) {
		return isOnCollectionPage( path ) ? SOURCE_READING_LIST_CUSTOM_LIST : SOURCE_READING_LIST_FEED;
	} else {
		return SOURCE_ARTICLE;
	}
};

/**
 * Track clicks to saved articles from the reading list special page
 *
 * @param {Object} instrument The instrument object
 */
function trackReadingListLinkClicks( instrument ) {
	document.addEventListener( 'click', ( event ) => {
		const link = findClosestElementFromEvent( event, 'a' );
		if (
			link &&
			link.closest( '.reading-lists-items' )
		) {
			recordInstrumentEvent( instrument, 'view_article', getSource( location.pathname ) );
		}
	} );
}

/**
 * @param {Object} instrument The instrument object
 * @param {string} action_subtype
 * @param {string} action_source
 * @param {string} action
 */
function recordInstrumentEvent( instrument, action_subtype, action_source, action = 'click' ) {
	// @ts-ignore
	instrument.send( action, {
		action_subtype,
		action_source
	} );
}

/**
 * @param {Object} instrument The instrument object
 */
const init = ( instrument ) => {
	// Only run on the reading lists special page
	if ( isReadingListPage() ) {
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

	mw.hook( 'readingLists.actions' ).add( ( action, subtype ) => {
		recordInstrumentEvent( instrument,
			subtype,
			getSource( location.pathname ),
			action
		);
	} );
};

$( () => {
	// @ts-ignore
	if ( window.QUnit ) {
		return;
	}
	// @ts-ignore
	init( mw.testKitchen.getInstrument( INSTRUMENT_NAME ) );
} );

module.exports = {
	init,
	trackBookmarkListIconButtonClicks,
	isOnCollectionPage
};
