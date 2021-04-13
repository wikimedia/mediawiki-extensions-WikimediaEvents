<?php

namespace WikimediaEvents;

use EventLogging;
use HTMLForm;
use MediaWiki\MediaWikiServices;
use MWCryptHash;
use User;
use UserBucketProvider;

// T261842: The Web team is interested in all skin changes involving Vector
// legacy and Vector latest.
class VectorPrefDiffInstrumentation {
	/**
	 * EventLogging schema to use.
	 * @var string
	 */
	private const SCHEMA = '/analytics/pref_diff/1.0.0';

	/**
	 * This must match the name used in $wgEventStreams config.
	 * @var string
	 */
	private const STREAM_NAME = 'mediawiki.pref_diff';

	/**
	 * Keep in sync with Vector Constants::SKIN_NAME.
	 * @var string
	 */
	private const VECTOR_SKIN_NAME = 'vector';

	/**
	 * Keep in sync with Vector Constants::PREF_KEY_SKIN_VERSION.
	 * @var string
	 */
	private const PREF_KEY_SKIN_VERSION = 'VectorSkinVersion';

	/**
	 * Config key with a string value that is used as a salt to hash the user id.
	 * This should be set in `wmf-config/PrivateSettings`.
	 * @var string
	 */
	private const SALT_CONFIG_KEY = 'WMEVectorPrefDiffSalt';

	/**
	 * Maps the Preferences form checkbox state (a key of `0` means an unchecked
	 * checkbox; a key of `1` means a checked checkbox) to the Vector skin
	 * version.
	 *
	 * Keep in sync with Vector HTMLLegacySkinVersionField::loadDataFromRequest.
	 * @var array
	 */
	private const CHECKBOX_TO_SKIN_VERSION_MAP = [
		1 => '1',
		0 => '2'
	];

	/**
	 * Hook executed on user's Special:Preferences form save.
	 *
	 * @param array $formData Form data submitted by user
	 * @param HTMLForm $form A preferences form
	 * @param User $user Logged-in user
	 * @param bool &$result Variable defining is form save successful
	 * @param array $oldPreferences
	 */
	public static function onPreferencesFormPreSave(
		array $formData,
		HTMLForm $form,
		User $user,
		&$result,
		$oldPreferences
	) {
		$event = self::createEventIfNecessary( $formData, $form, $user );

		if ( is_array( $event ) ) {
			EventLogging::submit( self::STREAM_NAME, $event );
		}
	}

	/**
	 * Helper method that returns a more meaningful skin name given the value of
	 * Vector's skin version. If skin is not Vector, simply return $skin.
	 *
	 * @param string $skin
	 * @param string $vectorSkinVersion Version of Vector skin.
	 * @return string
	 */
	private static function generateSkinVersionName( $skin, $vectorSkinVersion ) : string {
		return $skin === self::VECTOR_SKIN_NAME ? $skin . $vectorSkinVersion : $skin;
	}

	/**
	 * Creates an EventLogging event if a skin changes has been made that
	 * involves Vector legacy/latest and returns null otherwise.
	 *
	 * @param array $formData Form data submitted by user
	 * @param HTMLForm $form A preferences form
	 * @param User $user Logged-in user
	 *
	 * @return array|null An event array or null if an event cannot be
	 * produced.
	 */
	private static function createEventIfNecessary(
		array $formData,
		HTMLForm $form,
		User $user
	) : ?array {
		$salt = MediaWikiServices::getInstance()->getMainConfig()->get( self::SALT_CONFIG_KEY );
		// Exit early if preconditions aren't met.
		if ( !(
			$form->hasField( 'skin' ) &&
			$form->hasField( self::PREF_KEY_SKIN_VERSION ) &&
			$salt !== null
		) ) {
			return null;
		}

		// Get the old skin value from the form's default value.
		$oldSkin = (string)$form->getField( 'skin' )->getDefault();
		$oldSkinVersionName = self::generateSkinVersionName(
			$oldSkin,
			// Get the old skin version value from the form's default value. Because
			// this value is a bool, we must map it to the corresponding skin
			// version to get a meaningful skin version.
			self::CHECKBOX_TO_SKIN_VERSION_MAP[
				(int)$form->getField( self::PREF_KEY_SKIN_VERSION )->getDefault()
			] ?? ''
		);
		// Get the new skin value from the form data that was submitted.
		$newSkin = $formData['skin'] ?? '';
		$newSkinVersionName = self::generateSkinVersionName(
			$newSkin,
			$formData[ self::PREF_KEY_SKIN_VERSION ] ?? ''
		);

		// We are only interested in skin changes that involve Vector.
		if (
			in_array( self::VECTOR_SKIN_NAME, [ $oldSkin, $newSkin ] ) &&
			$oldSkinVersionName !== $newSkinVersionName
		) {

			return [
				'$schema' => self::SCHEMA,
				// Generate a unique deterministic hash using a salt. Don't send the
				// bare user id for privacy reasons.
				'user_hash' => MWCryptHash::hmac(
					(string)$user->getId(),
					$salt,
					false
				),
				'initial_state' => $oldSkinVersionName,
				'final_state' => $newSkinVersionName,
				'bucketed_user_edit_count' => UserBucketProvider::getUserEditCountBucket( $user ),
			];
		}

		return null;
	}
}