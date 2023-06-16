function scrapeDiffEventName( target ) {
	const closestLink = target.closest( 'a' );
	if ( !closestLink ) {
		return;
	}
	// Match link id
	switch ( closestLink.id ) {
		case 'differences-prevlink':
			return 'prev_link';
		case 'differences-nextlink':
			return 'next_link';
	}
	const parentNode = closestLink.parentNode;
	if ( !parentNode ) {
		return;
	}
	// Match parent id
	switch ( parentNode.id ) {
		case 'ca-history':
			return 'view_history';
	}
	let eventName;
	// Match link classes
	const linkClasses = closestLink.getAttribute( 'class' );
	if ( linkClasses ) {
		linkClasses.split( ' ' ).some( function ( linkClass ) {
			switch ( linkClass ) {
				case 'mw-usertoollinks-contribs':
					eventName = 'contribs';
					return true;
				case 'mw-userlink':
					eventName = 'user_link';
					return true;
				case 'mw-thanks-thank-link':
					// Ensure this is a confirmed thank
					if ( linkClasses.search( 'jquery-confirmable-button-yes' ) > -1 ) {
						eventName = 'thank';
						return true;
					}
					return false;
				default:
					return false;
			}
		} );
	}
	if ( eventName ) {
		return eventName;
	}
	// Match parent classes
	const parentClasses = parentNode.getAttribute( 'class' );
	if ( parentClasses ) {
		parentClasses.split( ' ' ).some( function ( parentClass ) {
			switch ( parentClass ) {
				case 'mw-diff-undo':
					eventName = 'undo';
					return true;
				case 'mw-rollback-link':
					eventName = 'rollback';
					return true;
				case 'mw-revdelundel-link':
					eventName = 'change_visibility';
					return true;
				case 'mw-tag-markers':
					eventName = 'tags';
					return true;
				default:
					// Match linked tags and return their class to differentiate
					if ( parentClass.indexOf( 'mw-tag-marker-' ) > -1 ) {
						eventName = parentClass;
						return true;
					} else {
						return false;
					}
			}
		} );
	}
	return eventName;
}

$( function () {
	// Log most interactions we care about via click event
	document.addEventListener( 'click', function ( event ) {
		const eventName = scrapeDiffEventName( event.target );
		if ( eventName ) {
			mw.eventLog.dispatch( `specialDiff.click.${eventName}` );
		}
	} );
	// Log watch/unwatch via hook
	mw.hook( 'wikipage.watchlistChange' ).add(
		function () {
			mw.eventLog.dispatch( 'specialDiff.click.watchlist' );
		} );
} );
