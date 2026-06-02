/**
 * Evaluating the impact of account creation CTAs triggered by interaction with the watchstar
 * (control) or the ReadingLists bookmark button (treatment) on mobile.
 *
 * See:
 * * [T420238 [WE 1.8.5 "Save article" Account Creation CTA experiment on mobile web](https://phabricator.wikimedia.org/T420238)
 */
const EXPERIMENT_NAME = 'account-creation-reading-list-cta';

const accountCreationExperiment =
	mw.testKitchen.compat.getExperiment( EXPERIMENT_NAME );

const setupControlInstrumentation = ( experiment ) => {
	const watchstar = document.querySelector( '#ca-watch' );

	// watchstar?.addEventListener would have been nicer in my opinion, but it broke eslint ):
	if ( watchstar ) {
		watchstar.addEventListener( 'click', () => {
			experiment.send( 'click', { action_subtype: 'save_article_to_watchlist' } );
		} );
	}

	mw.hook( 'skin.minerva.watchstarCtaDrawer.open' ).add( () => {
		experiment.send( 'init', { action_subtype: 'init_sign_up' } );

		const login = document.querySelector( '.drawer a[type="button"]' );
		const signUp = document.querySelector( '.drawer .cta-drawer__anchors a' );

		if ( login ) {
			login.addEventListener( 'click', () => {
				experiment.send( 'click', { action_subtype: 'login' } );
			} );
		}
		if ( signUp ) {
			signUp.addEventListener( 'click', () => {
				experiment.send( 'click', { action_subtype: 'sign_up' } );
			} );
		}
	} );
};

$( () => {
	if ( !accountCreationExperiment.isAssignedGroup( 'control', 'treatment' ) ) {
		return;
	}

	// send an exposure for all users in the experiment
	accountCreationExperiment.sendExposure();

	// if in control, add baseline tracking to the watchstar
	if ( accountCreationExperiment.isAssignedGroup( 'control' ) ) {
		setupControlInstrumentation( accountCreationExperiment );
	}
} );

// export for unit testing
module.exports = {
	test: {
		setupControlInstrumentation
	}
};
