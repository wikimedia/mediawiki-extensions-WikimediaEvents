<?php

namespace WikimediaEvents\EditCheck;

use MediaWiki\Extension\TestKitchen\Sdk\ExperimentManagerInterface;
use MediaWiki\Skin\Skin;
use MediaWiki\User\Options\UserOptionsLookup;

/**
 * TEMPORARY: A/B/C test of the edit suggestion indicator in read mode (T430712).
 * Remove this class and its hook registration when the test ends.
 *
 * The client sends the exposure event, in readSuggestionsCount.js, on pages where the
 * indicator shows. For the control group the indicator is hidden, so the client gets the
 * count but shows nothing.
 *
 * This class does not implement VisualEditorEditCheckReadSuggestionsIndicatorHook, because
 * CI for this extension does not load VisualEditor. The class must load without it.
 */
class ReadSuggestionsCountExperimentHooks {

	public const EXPERIMENT_NAME = 'editcheck-read-suggestions-count';

	/**
	 * Treatment groups. Each group name is also the indicator to show.
	 */
	public const TREATMENT_GROUPS = [ 'lightbulb', 'count' ];

	/**
	 * Skins where the indicator is on an edit button. readSuggestionsCount.js has the same skins.
	 */
	private const SKINS = [ 'vector-2022', 'minerva' ];

	private const MAX_EDIT_COUNT = 150;

	public function __construct(
		private readonly ExperimentManagerInterface $experimentManager,
		private readonly UserOptionsLookup $userOptionsLookup,
	) {
	}

	/**
	 * @see \MediaWiki\Extension\VisualEditor\VisualEditorEditCheckReadSuggestionsIndicatorHook
	 * @param Skin $skin
	 * @param string|null &$indicator
	 */
	public function onVisualEditorEditCheckReadSuggestionsIndicator( Skin $skin, ?string &$indicator ): void {
		$indicator = null;
		// This hook runs on all page views. Check eligibility first, because getExperiment()
		// logs and records metrics for users who are not enrolled.
		if ( !$this->isEligible( $skin ) ) {
			return;
		}
		$experiment = $this->experimentManager->getExperiment( self::EXPERIMENT_NAME );
		if ( !$experiment->isAssignedGroup( 'control', ...self::TREATMENT_GROUPS ) ) {
			return;
		}
		$indicator = $experiment->isAssignedGroup( 'control' ) ? 'hidden' : $experiment->getAssignedGroup();
	}

	/**
	 * Keep this the same as suggestionEligibility.js and readSuggestionsCount.js. Users that
	 * the client excludes get no exposure event, so they must not see an indicator.
	 */
	private function isEligible( Skin $skin ): bool {
		$user = $skin->getUser();
		return in_array( $skin->getSkinName(), self::SKINS, true ) &&
			$user->isNamed() &&
			(
				( $user->getEditCount() ?? 0 ) <= self::MAX_EDIT_COUNT ||
				// '0': VisualEditor showed the welcome dialog.
				// '2': the user is in the control group and readSuggestionsCount.js
				// found that they would see it.
				in_array( (string)$this->userOptionsLookup->getOption(
					$user, 'visualeditor-editcheck-showdiscoverabilitywelcome'
				), [ '0', '2' ], true )
			) &&
			!$this->userOptionsLookup->getBoolOption( $user, 'visualeditor-editcheck-suggestions' );
	}
}
