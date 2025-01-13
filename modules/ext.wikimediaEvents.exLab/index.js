module.exports = {
	ClickThroughRateInstrument: require( './ClickThroughRateInstrument.js' )
};

// ---

// Experimentation Lab Test 1
// ==========================

// This part of the file initializes the first end-to-end test of the Experimentation Lab (ExLab).
//
// Note that this is temporary code - it will be removed once Experiment Platform validate data
// collection (T383801).

const exLabTest1Enabled = require( './config.json' ).exLabTest1Enabled;

if ( exLabTest1Enabled ) {
	const ExperimentationLabTest1 = require( './ExLabTest1.js' );
	mw.requestIdleCallback( () => {
		ExperimentationLabTest1.init();
	} );
}
