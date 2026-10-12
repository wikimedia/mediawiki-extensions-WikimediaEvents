/**
 * This is for a Test Kitchen test (DE 4.3.4) and is likely obsolete/removable after 2027-01-15
 */

if ( !mw.config.get( 'wgWMEUserEligibleForEmailExperiment', false ) ) {
	return;
}

const EDIT_LINKS_SELECTOR = '#ca-edit a, #ca-ve-edit a, .mw-editsection a';

// Start fetching experiment enrollment information as early as possible in order to minimize the
// chance that the user clicks an edit link before the click handler has been bound.
const experimentPromise = mw.testKitchen.getExperiment( 'email-confirmation-enforcement-delayed' );

let toastMessage = null;

function buildToastMessage() {
	if ( toastMessage ) {
		return;
	}
	const confirmEmailUrl = mw.util.getUrl( 'Special:ConfirmEmail' );
	const $toastContent = $( '<span>' )
		.html( mw.message( 'wikimediaevents-de-4-3-4-email-confirmation-experiment-toast', confirmEmailUrl ).parse() );
	toastMessage = mw.util.messageBox( $toastContent[ 0 ], 'warning', true );
	$( toastMessage ).addClass( 'mw-email-confirmation-toast' );
	$( '<button>' )
		.attr( { type: 'button', 'aria-label': 'Close' } )
		.text( '×' )
		.appendTo( toastMessage );
}

$( () => {
	const $editLinks = $( EDIT_LINKS_SELECTOR );

	if ( $editLinks.length === 0 ) {
		return;
	}

	experimentPromise.then( ( experiment ) => {
		// If the user isn't enrolled in the experiment, then don't waste resources binding a
		// top-level, capturing event listener.
		if ( !experiment.getAssignedGroup() ) {
			return;
		}

		// Set a capturing event handler on the document, so that we can intercept clicks on the
		// edit links before other listeners get them
		document.addEventListener( 'click', ( event ) => {
			if ( event.target.closest( EDIT_LINKS_SELECTOR ) === null ) {
				return;
			}

			experiment.sendExposure();

			if ( experiment.isAssignedGroup( 'edit-blocked' ) ) {
				event.preventDefault();
				event.stopPropagation();

				buildToastMessage();

				// There is a little bit of race to load things here. Also, the notification
				// module returns a Promise, not an instance.
				mw.notify( toastMessage, { autoHide: false, tag: 'mw-email-confirmation-toast' } )
					.then( ( notification ) => {
						notification.$notification.addClass( 'mw-email-confirmation-toast' );
						// eslint-disable-next-line no-jquery/no-global-selector
						$( '#mw-notification-area' ).addClass( 'mw-email-confirmation-toast' );
					} );
			}

		}, { capture: true } );
	} );
} );
