// GeoIP mapping experiments (T332024)

function networkProbeInit() {
	const preventProbe = mw.cookie.get( 'PreventProbe' ); // Long-term cookie which is set to prevent the probe

	if ( preventProbe !== '1' ) {
		const networkProbeRandom = Math.random();
		const networkProbeLimitDefault = 0.00010; // 0.010 %

		// NetworkProbeLimit is a cookie which may be set by the
		// server to override networkProbeLimitDefault value
		let networkProbeLimit = mw.cookie.get( 'NetworkProbeLimit', '', networkProbeLimitDefault );

		if ( isNaN( networkProbeLimit ) ) {
			networkProbeLimit = networkProbeLimitDefault;
		}

		if ( networkProbeRandom <= networkProbeLimit ) {
			// Load Network Probe library and initiate a probe
			mw.loader.load( 'ext.wikimediaEvents.networkprobe' );
		}
	}
}

// Prevents the cookie I/O along these code paths from
// slowing down the initial paint and other critical
// tasks at page load.
mw.requestIdleCallback( networkProbeInit );
