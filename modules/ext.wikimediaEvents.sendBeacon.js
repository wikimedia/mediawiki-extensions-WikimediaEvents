( function ( mw, $ ) {
	var odds, isBeaconAvailable, baseEvent, imgEvent, beaconEvent;

	odds = 0.0001; // 1 in 10,000 chance

	if ( Math.random() < odds ) {
		mw.loader.using( [ 'mediawiki.user', 'schema.SendBeaconReliability' ] ).done( function () {
			isBeaconAvailable = !!navigator.sendBeacon;

			baseEvent = {
				browserSupportsSendBeacon: isBeaconAvailable,
				logId: mw.user.generateRandomSessionId()
			};

			imgEvent = $.extend( { method: 'logEvent' }, baseEvent );

			// We always log via logEvent (to at least get data on user agent and whether
			// it supports sendBeacon).  If sendBeacon is available, we also log via
			// logPersistentEvent.  Since logId is the same for both events, this allows
			// us to determine how common it is to have logEvent without
			// logPersistentEvent, or vice-versa.
			mw.eventLog.logEvent( 'SendBeaconReliability', imgEvent );

			if ( isBeaconAvailable ) {
				beaconEvent = $.extend( { method: 'logPersistentEvent' }, baseEvent );
				mw.eventLog.logPersistentEvent( 'SendBeaconReliability', beaconEvent );
			}
		} );
	}
}( mediaWiki, jQuery ) );
