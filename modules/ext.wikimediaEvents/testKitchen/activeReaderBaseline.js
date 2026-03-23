/**
 * This instrument sends one event at page load for users that exactly match:
 *   * are logged in
 *   * have made 0 edits
 *
 * We initially want this to run on 100% of enwiki traffic
 *
 * Interaction Data
 *   action_context: a secure hash of the user's id
 *
 * No contextual attributes
 *
 * Ideally a server-side transform would copy only the user id hash and rough
 *   timestamp to output
 */
const INSTRUMENT_NAME = 'active-reader-baseline-2026-03';

mw.loader.using( 'ext.testKitchen' ).then( () => {
	// logged in and 0 edits
	if ( mw.user.isNamed() && mw.config.get( 'wgUserEditCount' ) === 0 ) {

		hashString( String( mw.user.getId() ) ).then( ( userIdHash ) => {
			const instrument = mw.testKitchen.getInstrument( INSTRUMENT_NAME );
			const interactionData = {
				action_context: userIdHash
			};
			instrument.send( 'page_load', interactionData );
		} );
	}
} );

/**
 * Secure hash function
 *
 * @param {string} message what to hash
 *
 * @return {string} sha-256 hash of message
 */
async function hashString( message ) {
	const msgBuffer = new TextEncoder().encode( message );
	const hashBuffer = await crypto.subtle.digest( 'SHA-256', msgBuffer );
	const hashArray = Array.from( new Uint8Array( hashBuffer ) );
	return hashArray.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}
