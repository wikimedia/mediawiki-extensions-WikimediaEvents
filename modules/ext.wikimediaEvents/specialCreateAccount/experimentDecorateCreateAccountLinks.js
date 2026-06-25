function decorateCreateAccountLinks() {
	if ( mw.config.get( 'wgDBname' ) !== 'enwiki' ) {
		return;
	}

	if ( mw.config.get( 'skin' ) !== 'minerva' ) {
		return;
	}

	if ( !mw.user.isAnon() ) {
		return;
	}

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
			return 'we-1-8-account-creation-form-v2:unknown';
		}

		const exp = await mw.testKitchen.getExperiment( 'we-1-8-account-creation-form-v2' );
		const assignedGroup = exp.getAssignedGroup() === null ? 'unsampled' : exp.getAssignedGroup();

		// TODO: this is accessing internal properties for lack of an alternative.
		//       If this instrumentation is kept long-term, this should get a more stable interface.
		const isOverriddenExperiment = exp.name && exp.assigned;
		return 'we-1-8-account-creation-form-v2:' + assignedGroup + ( isOverriddenExperiment ? ':overridden' : '' );
	}

	function decorateLinksToAuthWikimediaOrg( experimentValue ) {
		// eslint-disable-next-line mediawiki/no-nodelist-unsupported-methods
		document.querySelectorAll( '[href*="Special:UserLogin"],[href*="Special:CreateAccount"]' ).forEach( ( element ) => {
			const hrefUrl = new URL( element.href );
			hrefUrl.searchParams.set( 'experiments', experimentValue );
			element.href = hrefUrl.toString();
		} );
	}

	mw.loader.using( 'ext.wikimediaEvents.testKitchen' ).then( async () => {
		const experimentValue = await getExperimentParamValue();
		decorateLinksToAuthWikimediaOrg( experimentValue );

		mw.hook( 've.newTarget' ).add( ( target ) => {
			target.overlay.once( 'editor-loaded', () => {
				// VE visual editor anon warning links
				decorateLinksToAuthWikimediaOrg( experimentValue );
			} );
		} );

		mw.hook( 'mobileFrontend.editorOpened' ).add( ( editor ) => {
			if ( editor === 'wikitext' ) {
				// VE source editor anon warning links
				decorateLinksToAuthWikimediaOrg( experimentValue );
			}
		} );
	} );
}

module.exports = decorateCreateAccountLinks;
