<?php
namespace WikimediaEvents\Tests\Unit;

use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWikiUnitTestCase;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationHandler;

/**
 * @covers \WikimediaEvents\CreateAccount\CreateAccountInstrumentationHandler
 */
class CreateAccountInstrumentationHandlerTest extends MediaWikiUnitTestCase {
	private ExtensionRegistry $extensionRegistry;
	private CreateAccountInstrumentationHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->extensionRegistry = $this->createMock( ExtensionRegistry::class );
		$this->handler = new CreateAccountInstrumentationHandler( $this->extensionRegistry );
	}

	/**
	 * @dataProvider provideShouldAddInstrumentation
	 */
	public function testShouldAddInstrumentationToSpecialCreateAccount(
		string $specialPageName,
		bool $isEventLoggingLoaded,
		bool $shouldAddModule
	): void {
		$outputPage = $this->createMock( OutputPage::class );
		if ( $shouldAddModule ) {
			$outputPage->expects( $this->once() )
				->method( 'addModules' )
				->with( 'ext.wikimediaEvents.createAccount' );
		} else {
			$outputPage->expects( $this->never() )
				->method( 'addModules' );
		}

		$specialPage = $this->createMock( SpecialPage::class );
		$specialPage->method( 'getName' )
			->willReturn( $specialPageName );
		$specialPage->method( 'getOutput' )
			->willReturn( $outputPage );

		$this->extensionRegistry->method( 'isLoaded' )
			->willReturnMap( [
				[ 'EventLogging', '*', $isEventLoggingLoaded ]
			] );

		$this->handler->onSpecialPageBeforeExecute( $specialPage, null );
	}

	public static function provideShouldAddInstrumentation(): iterable {
		yield 'not Special:CreateAccount' => [ 'UserLogin', true, false ];
		yield 'EventLogging not loaded' => [ 'CreateAccount', false, false ];
		yield 'Special:CreateAccount with EventLogging' => [ 'CreateAccount', true, true ];
	}
}
