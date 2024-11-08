<?php
namespace WikimediaEvents;

use FRPageConfig;
use MediaWiki\Extension\FlaggedRevs\Backend\Hook\FlaggedRevsStabilitySettingsChangedHook;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Stats\StatsFactory;

/**
 * Holds hook handlers for FlaggedRevs hooks related to the temporary accounts initiative (T357763).
 */
class FlaggedRevsTemporaryAccountsInstrumentation implements FlaggedRevsStabilitySettingsChangedHook {

	private StatsFactory $statsFactory;

	public function __construct( StatsFactory $statsFactory ) {
		$this->statsFactory = $statsFactory;
	}

	/** @inheritDoc */
	public function onFlaggedRevsStabilitySettingsChanged( $title, $newStabilitySettings, $userIdentity, $reason ) {
		// Don't track resetting stability settings to their default as a protection, as this is akin to doing an
		// un-protection which is not intended to be logged here.
		if ( FRPageConfig::configIsReset( $newStabilitySettings ) ) {
			return;
		}

		$this->statsFactory->withComponent( 'WikimediaEvents' )
			->getCounter( 'users_page_protect_total' )
			->setLabel( 'wiki', WikiMap::getCurrentWikiId() )
			->setLabel( 'source', 'FlaggedRevs' )
			->increment();
	}
}
