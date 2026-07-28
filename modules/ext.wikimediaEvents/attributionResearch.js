const EXPERIMENT_NAME = 'attribution-research-2026-06-run';
/**
 * To test in browser, uncomment these lines
 * if ( !mw.testKitchen.getExperiment( EXPERIMENT_NAME ) ) {
 *     mw.testKitchen.overrideExperimentGroup(EXPERIMENT_NAME, 'treatment');
 * }
 */
const DONE = 'mw-tk-ae-done';
const ERASED = 'mw-tk-ae-erase';
// expire in 36 days (TODO: change based on experiment length)
const EXPIRE_REMEMBER = 3110400;
const DEPENDENCIES = [ 'mediawiki.storage' ];

function remember( key ) {
	mw.storage.set( key, '1', EXPIRE_REMEMBER );
}
function was( key ) {
	return mw.storage.get( key ) === '1';
}

/**
 * Executes an action at or after the specified number of seconds.
 * (copied from SearchSatisfaction)
 *
 * @param {number[]} checkinTimes Times (in seconds from start) when the
 *  action should be executed.
 * @param {Function} fn The action to execute.
 * @private
 */
function interval( checkinTimes, fn ) {
	// found this example, wanted to do it, no idea how require works in mw
	// const visibleTimeout = require( 'mediawiki.visibleTimeout' );
	//     ...
	//     visibleTimeout.set( action, 1000 * timeout );
	// have no idea how require works in mw world, will figure out later
	let checkin = checkinTimes.shift();
	let timeout = checkin;
	function action() {
		const current = checkin;
		fn( current );
		checkin = checkinTimes.shift();
		if ( checkin ) {
			timeout = checkin - current;
			setTimeout( action, 1000 * timeout );
		}
	}
	setTimeout( action, 1000 * timeout );
}

function main( exp ) {
	// no longer in experiment scope somehow, do nothing
	if ( was( DONE ) || was( ERASED ) ) {
		return;
	}

	if ( !( exp && exp.isAssignedGroup( 'control', 'treatment' ) ) ) {
		return;
	}

	// set up session ticks to get a rough sense of time on page
	// previous research showed p75=64s and p90=166s
	const tickTimes = [ 5, 64, 166 ];
	interval( tickTimes, ( tick ) => exp.send( 'tick', { action_context: String( tick ) } ) );

	// (logged out)
	if ( mw.config.get( 'wgUserId' ) === null ) {
		const interactionData = {
			// first 300 characters sent, used to classify referrers in buckets
			action_context: document.referrer.slice( 0, 300 )
		};
		// NOTE: same attributes sent for all logged-out events right now
		const contextualAttributes = [
			// used to look up page popularity and topic for bucketing of readership
			'page_id',
			// used to look up group of namespaces for bucketing of readership
			'page_namespace_id',
			// used to contextualize the page_id for this action type
			'mediawiki_database'
		];
		// *** Reading event: the subject has viewed a page, while logged out
		exp.send( 'page_load', interactionData, contextualAttributes );

		// for VE, piggyback on track instrumentation, used in a few other instruments in this repo
		// NOTE: this is a great use case for factoring out an instrument similar to session length
		mw.trackSubscribe( 'editAttemptStep', ( _, data ) => {
			let action = null;
			// for the plain (2010) Wikitext editor, editAttemptStep only fires on 'firstChange'
			// but that feels less dangerous than adding an .on( 'submit' handler to the edit form
			if ( data && data.action === 'firstChange' ) {
				action = 'edit_first_change_' + data.editor_interface;
			}
			// saveAttempts can come from VE and mediawiki.org/wiki/2017_wikitext_editor
			if ( data && data.action === 'saveAttempt' ) {
				action = 'edit_attempt_' + data.editor_interface;
			}
			if ( action !== null ) {
				// *** Activity event: edit first change
				exp.send( action, {}, contextualAttributes );
			}
		} );

		// the banner redirect is done via JS so donation attempts might not send an event in time
		mw.hook( 'donate.attempt' ).add( ( source ) => {
			// *** Activity event: donation attempted
			exp.send( 'donate_attempt', { action_source: source }, contextualAttributes );
		} );
		// for other donation attempts that use the <a ...> convention described in T419569
		// eslint-disable-next-line no-jquery/no-global-selector
		$( 'a[data-mw-donate-attempt]' ).on( 'click', ( event ) => {
			const source = ( event && event.currentTarget && event.currentTarget.dataset ) ?
				event.currentTarget.dataset.mwDonateAttempt : '';
			// *** Activity event: donation attempted
			exp.send( 'donate_attempt', { action_source: source }, contextualAttributes );
		} );

	// (logged in)
	} else {

		// for this to work, the first page render after login has to have this flag
		// (double check data against real registrations to verify this assumption)
		if ( mw.config.get( 'wgTKAccountJustCreated' ) ) {
			const contextualAttributes = [
				// recorded with the subject_id to link to aggregated reader data
				'performer_id',
				// distinguishes between temp and named account registrations
				'performer_is_temp',
				// used to contextualize the performer_id for this action type
				'mediawiki_database'
			];
			// *** Conversion event: the subject has registered for an account
			exp.send( 'registration', {}, contextualAttributes );

			// if an edit was responsible for the account creation, instrument that
			mw.hook( 'postEdit' ).add( () => {
				// *** Conversion event: once per experiment send a successful edit
				exp.send( 'edit_successful', {}, contextualAttributes );
			} );
			remember( DONE );
		} else {
			// *** Utility event: user outside experiment scope due to prior account
			exp.send( 'erase_subject' );
			remember( ERASED );
		}
	}
}

mw.loader.using( DEPENDENCIES ).then(
	() => mw.testKitchen.getExperiment( EXPERIMENT_NAME ).then( main )
);
