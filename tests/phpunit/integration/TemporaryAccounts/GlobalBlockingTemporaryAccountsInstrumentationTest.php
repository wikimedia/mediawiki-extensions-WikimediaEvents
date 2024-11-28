<?php
namespace WikimediaEvents\Tests\Integration\TemporaryAccounts;

use FauxRequest;
use MediaWiki\Extension\GlobalBlocking\GlobalBlock;
use MediaWiki\Extension\GlobalBlocking\GlobalBlockingServices;
use MediaWiki\Extension\GlobalBlocking\Hooks\HookRunner;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \WikimediaEvents\TemporaryAccounts\GlobalBlockingTemporaryAccountsInstrumentation
 * @covers \WikimediaEvents\TemporaryAccounts\AbstractTemporaryAccountsInstrumentation
 */
class GlobalBlockingTemporaryAccountsInstrumentationTest extends MediaWikiIntegrationTestCase {

	use TemporaryAccountsInstrumentationTrait;
	use TempUserTestTrait;

	protected function setUp(): void {
		parent::setUp();

		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalBlocking' );
		// We don't want to test specifically the CentralAuth implementation of the CentralIdLookup. As such, force it
		// to be the local provider.
		$this->overrideConfigValue( MainConfigNames::CentralIdLookupProvider, 'local' );
	}

	/** @dataProvider provideShouldIncrementOnGloballyBlock */
	public function testShouldIncrementOnGloballyBlock( string $target, string $expectedUserLabel ) {
		$globalBlockStatus = GlobalBlockingServices::wrap( $this->getServiceContainer() )
			->getGlobalBlockManager()
			->block( $target, 'test', 'infinite', $this->getTestUser( [ 'steward' ] )->getUserIdentity() );
		$this->assertStatusGood( $globalBlockStatus );
		$this->assertCounterIncremented( 'global_block_target_total', [ $expectedUserLabel ], false );
	}

	public static function provideShouldIncrementOnGloballyBlock() {
		return [
			'Single IP address' => [ '1.2.3.4', 'anon' ],
			'IP range' => [ '1.2.3.4/23', 'iprange' ],
		];
	}

	public function testShouldIncrementOnGlobalBlockOfTemporaryAccount() {
		$this->enableAutoCreateTempUser();
		$this->testShouldIncrementOnGloballyBlock(
			$this->getServiceContainer()->getTempUserCreator()
				->create( null, new FauxRequest() )
				->getUser()->getName(),
			'temp'
		);
	}

	public function testShouldIncrementOnGlobalBlockOfBot() {
		$this->testShouldIncrementOnGloballyBlock(
			$this->getTestUser( [ 'bot' ] )->getUserIdentity()->getName(),
			'bot'
		);
	}

	public function testShouldIncrementOnGlobalBlockOfRegisteredUser() {
		$this->testShouldIncrementOnGloballyBlock( $this->getTestUser()->getUserIdentity()->getName(), 'normal' );
	}

	/** @dataProvider provideShouldIncrementOnGlobalBlockWhereLocalUserDoesNotExist */
	public function testShouldIncrementOnGlobalBlockWhereLocalUserDoesNotExist( $targetName, $expectedLabel ) {
		// Create a mock GlobalBlock class which returns our target name but with an ID of 0. We can't call
		// GlobalBlockManager::block like above, as the user must exist at least centrally for that to work.
		$mockGlobalBlock = $this->createMock( GlobalBlock::class );
		$mockGlobalBlock->method( 'getTargetName' )
			->willReturn( $targetName );
		$mockGlobalBlock->method( 'getTargetUserIdentity' )
			->willReturn( UserIdentityValue::newAnonymous( $targetName ) );
		// Run the GlobalBlockingGlobalBlockAudit hook with our mock GlobalBlock instance
		$globalBlockingHookRunner = new HookRunner( $this->getServiceContainer()->getHookContainer() );
		$globalBlockingHookRunner->onGlobalBlockingGlobalBlockAudit( $mockGlobalBlock );
		$this->assertCounterIncremented( 'global_block_target_total', [ $expectedLabel ], false );
	}

	public static function provideShouldIncrementOnGlobalBlockWhereLocalUserDoesNotExist() {
		return [
			'Temporary account' => [ '~2024-123456', 'temp' ],
			'Named account' => [ 'Named-account', 'normal' ],
		];
	}
}
