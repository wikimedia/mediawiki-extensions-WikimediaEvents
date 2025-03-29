/**
 * Checks if the feature is enabled.
 *
 * @param {string} name
 * @return {boolean}
 */
function isVectorFeatureEnabled( name ) {
	const className = 'vector-feature-' + name + '-enabled';
	return document.documentElement.classList.contains( className );
}

/**
 * Derives an event name value for a link which has no data-event-name
 * data attribute using the following checks:
 * 1) Finds the closest link that matches the '.vector-menu a' selector.
 *    If none, returns null.
 * 2) Checks if the link is inside a `vector-pinnable-element`.
 *    If so an event name is created with a suffix that includes the name of the
 *    feature and ".pinned" or ".unpinned" depending on the current state.
 * 4) If not, uses the targets elements ID attribute.
 * 5) If parent has no ID element this will return null.
 *
 * Note: this code runs in both Vector skins, and the pinned or unpinned
 * class will always be absent in legacy Vector.
 *
 * @param {jQuery} $target
 * @return {string|null}
 */
function getMenuLinkEventName( $target ) {
	const $closestLink = $target.closest( '.vector-menu a' );
	const closestLink = $closestLink[ 0 ];
	if ( !closestLink ) {
		return null;
	}
	const linkListItem = closestLink.parentNode;
	if ( !linkListItem ) {
		return;
	}
	/**
	 * T332612: Makes TOC heading clicks in unpinned state fire a
	 * generic event because tracking the section a user is reading
	 * within an article doesn't fall within the list of exceptions
	 * in the WMF Privacy Policy and thus isn't allowed.
	 * Source: See "Information Related to Your Use of the Wikimedia Sites"
	 * in https://foundation.wikimedia.org/wiki/Policy:Privacy_policy.
	 * NOTE: In pinned state, TOC heading clicks already fire generic event
	 * called 'ui.sidebar-toc'
	 */
	let id = linkListItem.id;
	if ( id.includes( 'toc' ) ) {
		// Replaces TOC heading ID with a generic prefix called 'toc-heading'.
		id = id.slice( 0, id.indexOf( 'toc-' ) ) + 'toc-heading';
	}
	const pinnableElement = $closestLink.closest( '.vector-pinnable-element' )[ 0 ];
	const pinnableElementHeader = pinnableElement ? pinnableElement.querySelector( '.vector-pinnable-header' ) : null;

	// Note not all pinnable-elements have a header so check both.
	if ( id && pinnableElement && pinnableElementHeader ) {
		const featureName = pinnableElementHeader.dataset.name || pinnableElementHeader.dataset.featureName || 'unknown';
		const pinnedState = isVectorFeatureEnabled( featureName ) ?
			'-enabled' :
			'-disabled';
		return id + '.' + featureName + pinnedState;
	} else {
		return id;
	}
}

const onClickTrack = function ( logEvent ) {
	return ( event ) => {
		const $target = $( event.target );
		const $closest = $target.closest( '[data-event-name]' );
		if ( $closest.length ) {
			// T352075
			// Click tracking of this kind is restricted to certain types of elements to avoid duplicate events.
			if ( [ 'A', 'BUTTON', 'INPUT' ].includes( $closest[ 0 ].tagName ) ) {
				const destination = $closest.attr( 'href' );
				logEvent( 'click', $closest.attr( 'data-event-name' ), destination );
			}
		} else {
			const eventName = getMenuLinkEventName( $target );
			if ( eventName ) {
				logEvent( 'click', eventName );
			}
		}
	};
};

module.exports = {
	onClickTrack
};
