<?php

namespace WikimediaEvents\Tests\Integration\Services;

use MediaWiki\Extension\TestKitchen\Sdk\InstrumentInterface;
use MediaWiki\Extension\TestKitchen\Sdk\InstrumentManagerInterface;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\Services\EmailConfirmationBannerInstrumentLogger;

/**
 * @covers \WikimediaEvents\Services\EmailConfirmationBannerInstrumentLogger
 */
class EmailConfirmationBannerInstrumentLoggerTest extends MediaWikiIntegrationTestCase {

	public function testLogSendsActionToInstrument(): void {
		$instrument = $this->createMock( InstrumentInterface::class );
		$instrument->expects( $this->once() )
			->method( 'send' )
			->with( 'email_confirmed', [] );

		$manager = $this->createMock( InstrumentManagerInterface::class );
		$manager->expects( $this->once() )
			->method( 'getInstrument' )
			->with( 'email-confirmation-banner-2026-06' )
			->willReturn( $instrument );

		$logger = new EmailConfirmationBannerInstrumentLogger( $manager );
		$logger->log( 'email_confirmed' );
	}

	public function testLogPassesDataToInstrument(): void {
		$instrument = $this->createMock( InstrumentInterface::class );
		$instrument->expects( $this->once() )
			->method( 'send' )
			->with( 'email_invalidated', [ 'action_source' => 'preferences' ] );

		$manager = $this->createMock( InstrumentManagerInterface::class );
		$manager->method( 'getInstrument' )
			->with( 'email-confirmation-banner-2026-06' )
			->willReturn( $instrument );

		$logger = new EmailConfirmationBannerInstrumentLogger( $manager );
		$logger->log( 'email_invalidated', [ 'action_source' => 'preferences' ] );
	}
}
