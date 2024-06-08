/* global ve */
mw.hook( 've.activationComplete' ).add( () => {
	if ( !ve.init.target.saveFields.campaign ) {
		ve.init.target.saveFields.campaign = function () {
			return mw.util.getParamValue( 'campaign' ) || '';
		};
	}
} );
