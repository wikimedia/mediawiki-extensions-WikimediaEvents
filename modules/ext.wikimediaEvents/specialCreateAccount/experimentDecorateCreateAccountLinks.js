function decorateCreateAccountLinks() {
	if ( mw.config.get( 'wgDBname' ) !== 'enwiki' ) {
		return;
	}

	if ( mw.config.get( 'skin' ) === 'minerva' ) {
		return;
	}

	if ( !mw.user.isAnon() ) {
		return;
	}
	const ACCOUNT_CREATION_NO_BENEFITS_DESKTOP_EXPERIMENT = 'we-1-8-account-creation-no-desktop-benefits';

	async function getExperimentParamValue() {
		const isUserLogin = mw.config.get( 'wgCanonicalSpecialPageName' ) === 'Userlogin';
		if ( isUserLogin ) {
			// TODO: this is building on the fact that this code is the only place that adds this param.
			//       If ever this approach gets more usage, then this logic needs to become smarter.
			const experimentsParam = mw.util.getParamValue( 'experiments' ) ||
				( mw.util.getArrayParam( 'experiments' ) && mw.util.getArrayParam( 'experiments' )[ 0 ] );
			if ( experimentsParam ) {
				return experimentsParam;
			}
			return ACCOUNT_CREATION_NO_BENEFITS_DESKTOP_EXPERIMENT + ':unknown';
		}

		const exp = await mw.testKitchen.getExperiment( ACCOUNT_CREATION_NO_BENEFITS_DESKTOP_EXPERIMENT );
		const assignedGroup = exp.getAssignedGroup() === null ? 'unsampled' : exp.getAssignedGroup();

		// TODO: this is accessing internal properties for lack of an alternative.
		//       If this instrumentation is kept long-term, this should get a more stable interface.
		const isOverriddenExperiment = exp.name && exp.assigned;
		return ACCOUNT_CREATION_NO_BENEFITS_DESKTOP_EXPERIMENT + ':' + assignedGroup + ( isOverriddenExperiment ? ':overridden' : '' );
	}

	function decorateLinksToAuthWikimediaOrg( experimentValue ) {
		// eslint-disable-next-line mediawiki/no-nodelist-unsupported-methods
		document.querySelectorAll( '[href*="Special:UserLogin"],[href*="Special:CreateAccount"]' ).forEach( ( element ) => {
			const hrefUrl = new URL( element.href );
			hrefUrl.searchParams.set( 'experiments[0]', experimentValue );
			element.href = hrefUrl.toString();
		} );
	}

	mw.loader.using( 'ext.wikimediaEvents.testKitchen' ).then( async () => {
		const experimentValue = await getExperimentParamValue();
		decorateLinksToAuthWikimediaOrg( experimentValue );
	} );
}

module.exports = decorateCreateAccountLinks;
