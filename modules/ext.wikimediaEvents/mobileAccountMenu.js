const EXPERIMENT_NAME = 'we-1-8-mobile-account-menu';
const SCHEMA_NAME = '/analytics/product_metrics/web/base/2.0.0';
const STREAM_NAME = 'mediawiki.product_metrics.contributors.experiments';

function setUpAccountMenuInstrumentation() {
	// Experiment only monitors wikis with a MinervaNeue skin
	if ( mw.config.get( 'skin' ) !== 'minerva' ) {
		return;
	}

	// Experiment only monitors users that aren't logged in
	if ( !mw.user.isAnon() ) {
		return;
	}

	const experimentPromise = mw.loader.using( [ 'ext.testKitchen', 'ext.wikimediaEvents.testKitchen' ] ).then( () => {
		const experiment = mw.testKitchen.compat.getExperiment( EXPERIMENT_NAME );
		experiment.setSchema( SCHEMA_NAME );
		experiment.setStream( STREAM_NAME );
		return experiment;
	} ).catch( ( error ) => {
		mw.log( 'Error loading ext.testKitchen module:', error );
		return null;
	} );

	experimentPromise.then( ( experiment ) => {
		experiment.sendExposure();

		const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.testKitchen' );
		if ( document.querySelector( '#minerva-user-menu-checkbox' ) ) {
			ClickThroughRateInstrument.start( '#minerva-user-menu-checkbox', 'user account menu icon', experiment );
		}

		const userMenuOpener = document.querySelector( '.minerva-user-navigation' );
		let firstOpeningOfUserMenu = true;
		userMenuOpener.addEventListener( 'click', () => {
			if ( firstOpeningOfUserMenu ) {
				ClickThroughRateInstrument.start( '.user-account-menu-createaccount', 'user account menu create account button', experiment );
				ClickThroughRateInstrument.start( '.user-account-menu-login', 'user account menu button to log in', experiment );
				firstOpeningOfUserMenu = false;
			}
		} );

		const hamburgerMenuOpener = document.querySelector( '#main-menu-input' );
		let firstOpeningOfHamburgerMenu = true;
		hamburgerMenuOpener.addEventListener( 'click', () => {
			if ( firstOpeningOfHamburgerMenu ) {
				ClickThroughRateInstrument.start( '.toggle-list-item__anchor.menu__item--createaccount',
					'hamburger menu button to create an account', experiment );
				ClickThroughRateInstrument.start( '.toggle-list-item__anchor.menu__item--login',
					'hamburger menu button to log in', experiment );
				firstOpeningOfHamburgerMenu = false;
			}
		} );
	} );
}

module.exports = setUpAccountMenuInstrumentation;
