<?php

declare( strict_types = 1 );

namespace WikimediaEvents\Tests\Integration\AccountCreation;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Context\RequestContext;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\AccountCreation\AccountCreationHandler;
use WikimediaEvents\AccountCreation\AccountCreationLogger;

/**
 * @covers \WikimediaEvents\AccountCreation\AccountCreationHandler
 */
class AccountCreationHandlerIntegrationTest extends MediaWikiIntegrationTestCase {

	protected function tearDown(): void {
		RequestContext::resetMain();
		parent::tearDown();
	}

	public function testBeforePageDisplayAddsReadingListsAccountJustCreatedJsConfigVar(): void {
		$request = new FauxRequest( [ 'readingListsAccountJustCreated' => '1' ] );
		$context = RequestContext::getMain();
		$context->setRequest( $request );
		$context->setTitle( $this->getServiceContainer()->getTitleFactory()->newMainPage() );
		$accountCreationLogger = $this->createMock( AccountCreationLogger::class );
		$accountCreationLogger->expects( $this->once() )
			->method( 'logPageImpression' );

		$handler = new AccountCreationHandler(
			$accountCreationLogger,
			$this->createNoOpMock( ExtensionRegistry::class ),
			$this->createNoOpMock( AuthManager::class ),
		);

		$handler->onBeforePageDisplay( $context->getOutput(), $context->getSkin() );

		$this->assertSame(
			'1',
			$context->getOutput()->getJsConfigVars()['wgReadingListsAccountJustCreated']
		);
	}

	public function testBeforePageDisplayDoesNotAddReadingListsAccountJustCreatedJsConfigVarWhenMissing(): void {
		$request = new FauxRequest();
		$context = RequestContext::getMain();
		$context->setRequest( $request );
		$context->setTitle( $this->getServiceContainer()->getTitleFactory()->newMainPage() );
		$accountCreationLogger = $this->createMock( AccountCreationLogger::class );
		$accountCreationLogger->expects( $this->once() )
			->method( 'logPageImpression' );

		$handler = new AccountCreationHandler(
			$accountCreationLogger,
			$this->createNoOpMock( ExtensionRegistry::class ),
			$this->createNoOpMock( AuthManager::class ),
		);

		$handler->onBeforePageDisplay( $context->getOutput(), $context->getSkin() );

		$this->assertArrayNotHasKey(
			'wgReadingListsAccountJustCreated',
			$context->getOutput()->getJsConfigVars()
		);
	}
}
