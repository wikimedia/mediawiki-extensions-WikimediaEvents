<?php

namespace WikimediaEvents\Tests\Integration\Services;

use MediaWikiIntegrationTestCase;
use WikimediaEvents\Services\EmailConfirmationBannerExperimentLogger;

/**
 * @covers \WikimediaEvents\Services\EmailConfirmationBannerExperimentLogger
 * @group Database
 */
class EmailConfirmationBannerExperimentLoggerTest extends MediaWikiIntegrationTestCase {
	public function testLogIsNoOpWhenTestKitchenServiceIsUnavailable(): void {
		$logger = $this->getServiceContainer()->get(
			'WikimediaEventsEmailConfirmationBannerExperimentLogger'
		);

		$this->assertInstanceOf( EmailConfirmationBannerExperimentLogger::class, $logger );
		$logger->log( 'email_confirmed' );
		$this->addToAssertionCount( 1 );
	}
}
