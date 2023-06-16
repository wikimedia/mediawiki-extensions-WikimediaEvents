function scrapeDiffEventName( target ) {
	// #mw-mf-diffview is a common anscestor of all targets of concern
	const closestLink = target.closest( '#mw-mf-diffview a' );
	if ( !closestLink ) {
		return;
	}
	let eventName;
	// Match target classes
	const targetClasses = target.getAttribute( 'class' );
	if ( targetClasses ) {
		Array.prototype.some.call( targetClasses.split( ' ' ), function ( targetClass ) {
			switch ( targetClass ) {
				case 'mw-ui-icon-thanks-userTalk':
					eventName = 'thank';
					return true;
			}
		} );
	}
	if ( eventName ) {
		return eventName;
	}
	const parentNode = closestLink.parentNode;
	if ( !parentNode ) {
		return;
	}
	// Match parent classes
	const parentClasses = parentNode.getAttribute( 'class' );
	if ( parentClasses ) {
		Array.prototype.some.call( parentClasses.split( ' ' ), function ( parentClass ) {
			switch ( parentClass ) {
				case 'revision-history-prev':
					eventName = 'prev_link';
					return true;
				case 'revision-history-next':
					eventName = 'next_link';
					return true;
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
} );
