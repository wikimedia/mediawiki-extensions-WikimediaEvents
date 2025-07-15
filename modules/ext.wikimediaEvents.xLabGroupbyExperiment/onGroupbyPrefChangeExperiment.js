mw.loader.using( 'ext.xLab' ).then( () => {
	const experiment = mw.xLab.getExperiment( 'fy24-25-we-1-7-rc-grouping-toggle' );
	if ( experiment.isAssignedGroup( 'control', 'toggle-shown' ) ) {
		const initialRecentChangePref = document.querySelector( '#mw-input-wpusenewrc > input' ).checked;
		document.querySelector( '.mw-htmlform-submit-buttons > span > button' ).addEventListener( 'click', () => {
			const newRecentChangePref = document.querySelector( '#mw-input-wpusenewrc > input' ).checked;
			if ( initialRecentChangePref !== newRecentChangePref ) {
				experiment.send( 'preference-change', {
					action_subtype: newRecentChangePref ? 'on' : 'off',
					action_context: JSON.stringify( { location: 'preferences' } )
				} );
			}
		} );
	}
}, () => {
	// noop if module not found
} );
