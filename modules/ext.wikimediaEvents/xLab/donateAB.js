const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.xLab' );

mw.loader.using( 'ext.xLab' ).then( () => {
	const experiment = mw.xLab.getExperiment( 'we-3-2-3-donate-ab-test-1' );

	if ( experiment.isAssignedGroup( 'control', 'treatment' ) ) {
		ClickThroughRateInstrument.start(
			'.re-experiment-vector-donate-entry-point-variation',
			'Donate Link',
			experiment
		);
	}
} );
