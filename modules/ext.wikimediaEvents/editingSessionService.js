'use strict';

/**
 * Get editing session ID with fallback priority:
 * 1. mw.config.get('wgWMESchemaEditAttemptStepSessionId')
 * 2. URL parameter 'editingStatsId'
 * 3. Form field '#editingStatsId'
 * 4. Existing session ID (if provided)
 * 5. Generate new random session ID
 *
 * @param {string|null} existingSessionId Existing session ID to use as fallback
 * @return {string} Editing session ID
 */
function getEditingSessionId( existingSessionId = null ) {
	const configId = mw.config.get( 'wgWMESchemaEditAttemptStepSessionId' );
	if ( configId ) {
		return configId;
	}

	const urlParam = new URL( location.href ).searchParams.get( 'editingStatsId' );
	if ( urlParam ) {
		return urlParam;
	}

	// eslint-disable-next-line no-jquery/no-global-selector
	const formField = $( '#editingStatsId' ).val();
	if ( formField ) {
		return formField;
	}

	if ( existingSessionId ) {
		return existingSessionId;
	}

	return mw.user.generateRandomSessionId();
}

module.exports = { getEditingSessionId };
