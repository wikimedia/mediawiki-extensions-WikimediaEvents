<?php

namespace WikimediaEvents\Tests\Integration\EmailConfirmation;

use MediaWiki\Extension\TestKitchen\Sdk\ExperimentManagerInterface;
use MediaWiki\Output\OutputPage;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use WikimediaEvents\EmailConfirmation\EmailConfirmationBannerInstrumentLogger;
use WikimediaEvents\EmailConfirmation\EmailConfirmationHooks;

/**
 * @covers \WikimediaEvents\EmailConfirmation\EmailConfirmationHooks
 * @group Database
 */
class EmailConfirmationHooksTest extends \MediaWikiIntegrationTestCase {

	private function newHookHandler(
		?EmailConfirmationBannerInstrumentLogger $logger = null
	): EmailConfirmationHooks {
		return new EmailConfirmationHooks(
			$this->getServiceContainer()->getMainConfig(),
			$this->createMock( ExperimentManagerInterface::class ),
			$logger ?? $this->createMock( EmailConfirmationBannerInstrumentLogger::class )
		);
	}

	public function testEmailConfirmationBannerTrackingAddsModule(): void {
		$this->overrideConfigValues( [ 'EmailConfirmationBanner' => true, 'EmailAuthentication' => true ] );

		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( true );
		$user->method( 'getEmail' )->willReturn( 'user@example.com' );
		$user->method( 'isEmailConfirmed' )->willReturn( false );

		$title = $this->createMock( Title::class );
		$title->method( 'isSpecial' )->willReturn( false );

		$addedModules = [];
		$out = $this->createMock( OutputPage::class );
		$out->method( 'addModules' )->willReturnCallback(
			static function ( $module ) use ( &$addedModules ) {
				$addedModules[] = $module;
			}
		);
		$out->method( 'getTitle' )->willReturn( $title );
		$out->method( 'getUser' )->willReturn( $user );

		$skin = $this->createMock( Skin::class );

		$handler = $this->newHookHandler();
		$handler->onBeforePageDisplay( $out, $skin );

		$this->assertContains( 'ext.wikimediaEvents.emailConfirmationBanner', $addedModules );
	}

	public function testConfirmEmailCompleteLogsEvent(): void {
		$logger = $this->createMock( EmailConfirmationBannerInstrumentLogger::class );
		$logger->expects( $this->once() )
			->method( 'log' )
			->with( 'email_confirmed' );

		$handler = $this->newHookHandler( $logger );
		$handler->onConfirmEmailComplete( $this->createMock( User::class ) );
	}

	public function testInvalidateEmailCompleteLogsEvent(): void {
		$logger = $this->createMock( EmailConfirmationBannerInstrumentLogger::class );
		$logger->expects( $this->once() )
			->method( 'log' )
			->with( 'email_invalidated' );

		$handler = $this->newHookHandler( $logger );
		$handler->onInvalidateEmailComplete( $this->createMock( User::class ) );
	}
}
