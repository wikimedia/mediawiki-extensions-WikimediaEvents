/**
 * This is for a Test Kitchen test (DE 4.3.4) and is likely obsolete/removable after 2027-01-15
 */

$( () => {
	const wgWMEUserEligibleForEmailExperiment = mw.config.get( 'wgWMEUserEligibleForEmailExperiment' );
	// eslint-disable-next-line no-jquery/no-global-selector
	const $editLinks = $( '#ca-edit a, #ca-ve-edit a, .mw-editsection a' );
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

	if ( $editLinks.length && wgWMEUserEligibleForEmailExperiment ) {
		// Set a capturing event handler on the document, so that we can intercept clicks on the
		// edit links before other listeners get them
		document.addEventListener( 'click', ( event ) => {
			if ( event.target.closest( '#ca-edit a, #ca-ve-edit a, .mw-editsection a' ) === null ) {
				return;
			}

			const experiment = mw.tk.compat.getExperiment( 'email-confirmation-enforcement-delayed' );
			experiment.sendExposure();

			if ( experiment.isAssignedGroup( 'edit-blocked' ) ) {
				event.preventDefault();
				event.stopPropagation();

				buildToastMessage();

				// There is a little bit of race to load things here. Also, the notification module
				// returns a Promise, not an instance.
				mw.notify( toastMessage, { autoHide: false, tag: 'mw-email-confirmation-toast' } )
					.then( ( notification ) => {
						notification.$notification.addClass( 'mw-email-confirmation-toast' );
						// eslint-disable-next-line no-jquery/no-global-selector
						$( '#mw-notification-area' ).addClass( 'mw-email-confirmation-toast' );
					} );
			}

		}, { capture: true } );
	}
} );
