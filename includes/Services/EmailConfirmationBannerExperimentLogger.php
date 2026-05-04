<?php

namespace WikimediaEvents\Services;

use MediaWiki\MediaWikiServices;

class EmailConfirmationBannerExperimentLogger {
	private const EMAIL_CONFIRMATION_BANNER_EXPERIMENT = 'ab-test-email-confirmation-banner';
	private const TEST_KITCHEN_EXPERIMENT_MANAGER = 'TestKitchen.Sdk.ExperimentManager';

	public function log( string $action ): void {
		$services = MediaWikiServices::getInstance();
		if ( !$services->hasService( self::TEST_KITCHEN_EXPERIMENT_MANAGER ) ) {
			return;
		}

		$experimentManager = $services->getService( self::TEST_KITCHEN_EXPERIMENT_MANAGER );
		$experiment = $experimentManager->getExperiment( self::EMAIL_CONFIRMATION_BANNER_EXPERIMENT );
		$experiment->send( $action, [] );
	}
}
