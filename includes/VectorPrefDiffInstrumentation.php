<?php

namespace WikimediaEvents;

use HTMLForm;
use MediaWiki\Extension\EventLogging\EventLogging;
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
	private const STREAM_NAME = 'mediawiki.skin_diff';

	/**
	 * Keep in sync with Vector Constants::SKIN_NAME_LEGACY.
	 * @var string
	 */
	private const VECTOR_SKIN_NAME = 'vector';

	/**
	 * Keep in sync with Vector Constants::SKIN_NAME_MODERN.
	 * @var string
	 */
	private const VECTOR_SKIN_NAME_MODERN = 'vector-2022';

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
	 * Maps skin names to vector version names.
	 * @var array
	 */
	private const SEPARATE_SKINS_TO_SKIN_VERSION_NAME_MAP = [
		self::VECTOR_SKIN_NAME => 'vector1',
		self::VECTOR_SKIN_NAME_MODERN => 'vector2'
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
	 * @param string|bool $vectorSkinVersion Version of Vector skin in string
	 * form (e.g. '1' or '2') or bool form (e.g. true or false).
	 * @return string
	 */
	private static function generateSkinVersionName( $skin, $vectorSkinVersion ): string {
		// The value of `$vectorSkinVersion` can either be a string or a bool
		// depending on whether the field's `getDefault` method or
		// `loadDataFromRequest` method is called. [1] The `getDefault` method can
		// be called if the field is disabled which can occur through the
		// GlobalPreferences extension. [2] Therefore, we must check whether the
		// value is a string or a bool to get the correct skin version.
		//
		// Please see T261842#7084144 for additional context.
		//
		// [1] https://github.com/wikimedia/mediawiki/blob/fca9c972de9333bfbf881fdaa639abe27b9de4da/includes/htmlform/HTMLForm.php#L1861-L1865
		// [2] https://github.com/wikimedia/mediawiki-extensions-GlobalPreferences/blob/ccf4c9d470bfc0714119153b592bcc167dceccc6/includes/GlobalPreferencesFactory.php#L175
		if ( is_bool( $vectorSkinVersion ) ) {
			// Since this value is a bool, we must map it to the corresponding skin
			// version to get a meaningful skin version.
			$vectorSkinVersion = self::CHECKBOX_TO_SKIN_VERSION_MAP[
				(int)$vectorSkinVersion
			];
		}

		return $skin === self::VECTOR_SKIN_NAME ? $skin . $vectorSkinVersion : $skin;
	}

	/**
	 * Helper method that converts the legacy or modern Vector skin names (e.g.
	 * 'vector-2022') into skin version names (e.g. 'vector2'). Returns $skin if
	 * $skin is not an interation of Vector.
	 * @param string $skin
	 * @return string
	 */
	private static function generateSkinVersionNameFromSeparateSkins( $skin ): string {
		return self::SEPARATE_SKINS_TO_SKIN_VERSION_NAME_MAP[ $skin ] ?? $skin;
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
	): ?array {
		$salt = MediaWikiServices::getInstance()->getMainConfig()->get( self::SALT_CONFIG_KEY );
		// Exit early if preconditions aren't met.
		if ( !(
			$form->hasField( 'skin' ) &&
			$salt !== null
		) ) {
			return null;
		}

		// T291098: Check if 'vector-2022 is an option.
		$hasVectorSkinNameModern = in_array( self::VECTOR_SKIN_NAME_MODERN, $form->getField( 'skin' )->getOptions() );

		if ( !$hasVectorSkinNameModern && !$form->hasField( self::PREF_KEY_SKIN_VERSION ) ) {
			return null;
		}

		// Get the old skin value from the form's default value.
		$oldSkin = (string)$form->getField( 'skin' )->getDefault();
		$oldSkinVersionName = $hasVectorSkinNameModern ?
			self::generateSkinVersionNameFromSeparateSkins( $oldSkin ) :
			self::generateSkinVersionName(
			$oldSkin,
			$form->getField( self::PREF_KEY_SKIN_VERSION )->getDefault()
		);
		// Get the new skin value from the form data that was submitted.
		$newSkin = $formData['skin'] ?? '';
		$newSkinVersionName = $hasVectorSkinNameModern ?
			self::generateSkinVersionNameFromSeparateSkins( $newSkin ) :
			self::generateSkinVersionName(
			$newSkin,
			$formData[ self::PREF_KEY_SKIN_VERSION ] ?? ''
		);

		$involvesVector =
				in_array( 'vector1', [ $oldSkinVersionName, $newSkinVersionName ], true ) ||
				in_array( 'vector2', [ $oldSkinVersionName, $newSkinVersionName ], true );

		// We are only interested in skin changes that involve Vector.
		if (
			$involvesVector &&
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
