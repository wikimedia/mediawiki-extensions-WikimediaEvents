<?php

namespace WikimediaEvents\Tests\Integration\EditPage;

use MediaWiki\Block\Block;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Extension\GlobalBlocking\GlobalBlock;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\EditPage\CaptchaScoreBlocksHook;

/**
 * @covers \WikimediaEvents\EditPage\CaptchaScoreBlocksHook
 * @group Database
 */
class CaptchaScoreBlocksHookTest extends MediaWikiIntegrationTestCase {

	private const string SCHEMA = '/analytics/mediawiki/hcaptcha/risk_score/1.5.0';

	protected function setUp(): void {
		parent::setUp();

		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );
	}

	/** @dataProvider provideRiskScoreRetrievedForBlocksEvents */
	public function testRiskScoreRetrievedForBlocksSubmitsEvent(
		int $blockType,
		string $expectedAction
	): void {
		$services = $this->getServiceContainer();
		$blockId = 42;
		$riskScore = 0.7;
		$user = UserIdentityValue::newAnonymous( '1.2.3.4' );
		$request = new FauxRequest( [], true );
		RequestContext::getMain()->setRequest( $request );

		$mockBlock = $this->createMock( DatabaseBlock::class );
		$mockBlock
			->method( 'getType' )
			->willReturn( $blockType );
		$mockBlock
			->method( 'getId' )
			->willReturn( $blockId );

		$userEntitySerializer = $services->get( 'EventBus.UserEntitySerializer' );
		$eventSubmitter = $this->createMock( EventSubmitter::class );
		$eventSubmitter
			->expects( $this->once() )
			->method( 'submit' )
			->with(
				'mediawiki.hcaptcha.risk_score',
				[
					'$schema' => self::SCHEMA,
					'action' => $expectedAction,
					'wiki_id' => WikiMap::getCurrentWikiId(),
					'identifier' => $blockId,
					'identifier_type' => 'local_block',
					'performer' => $userEntitySerializer->toArray( $user ),
					'http' => [ 'method' => 'POST' ],
					'risk_score' => $riskScore,
					'mw_entry_point' => MW_ENTRY_POINT,
				]
			);

		$hook = new CaptchaScoreBlocksHook( $eventSubmitter, $userEntitySerializer );

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			$riskScore,
			[ $mockBlock ],
			$user,
			'',
			$request
		);
	}

	public static function provideRiskScoreRetrievedForBlocksEvents(): array {
		return [
			'IP block' => [
				'blockType' => Block::TYPE_IP,
				'expectedAction' => 'ip_block_edit_attempt',
			],
			'Range block' => [
				'blockType' => Block::TYPE_RANGE,
				'expectedAction' => 'ip_range_block_edit_attempt',
			],
			'Auto block' => [
				'blockType' => Block::TYPE_AUTO,
				'expectedAction' => 'auto_block_edit_attempt',
			],
		];
	}

	public function testRiskScoreRetrievedForBlocksSubmitsEventWithPageViewId(): void {
		$services = $this->getServiceContainer();
		$blockId = 42;
		$riskScore = 0.7;
		$user = UserIdentityValue::newAnonymous( '1.2.3.4' );
		$request = new FauxRequest( [], true );
		RequestContext::getMain()->setRequest( $request );

		$mockBlock = $this->createMock( DatabaseBlock::class );
		$mockBlock->method( 'getType' )->willReturn( Block::TYPE_IP );
		$mockBlock->method( 'getId' )->willReturn( $blockId );

		$userEntitySerializer = $services->get( 'EventBus.UserEntitySerializer' );
		$eventSubmitter = $this->createMock( EventSubmitter::class );
		$eventSubmitter->expects( $this->once() )
			->method( 'submit' )
			->with(
				'mediawiki.hcaptcha.risk_score',
				$this->callback( static fn ( array $event ) =>
					( $event['page_view_id'] ?? null ) === 'abc123'
				)
			);

		$hook = new CaptchaScoreBlocksHook( $eventSubmitter, $userEntitySerializer );

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			$riskScore,
			[ $mockBlock ],
			$user,
			'abc123',
			$request
		);
	}

	public function testRiskScoreRetrievedForBlocksSkipsUnsupportedBlockType(): void {
		$services = $this->getServiceContainer();

		$mockBlock = $this->createMock( DatabaseBlock::class );
		$mockBlock
			->method( 'getType' )
			->willReturn( Block::TYPE_USER );

		$eventSubmitter = $this->createMock( EventSubmitter::class );
		$eventSubmitter
			->expects( $this->never() )
			->method( 'submit' );

		$hook = new CaptchaScoreBlocksHook( $eventSubmitter, $services->get( 'EventBus.UserEntitySerializer' ) );

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			0.5,
			[ $mockBlock ],
			UserIdentityValue::newAnonymous( '1.2.3.4' ),
			'',
			new FauxRequest( [], true )
		);
	}

	public function testRiskScoreRetrievedForBlocksSubmitsOneEventPerBlock(): void {
		$services = $this->getServiceContainer();
		$riskScore = 0.3;
		$user = UserIdentityValue::newAnonymous( '1.2.3.4' );
		$request = new FauxRequest( [], true );
		RequestContext::getMain()->setRequest( $request );

		$ipBlock = $this->createMock( DatabaseBlock::class );
		$ipBlock
			->method( 'getType' )
			->willReturn( Block::TYPE_IP );
		$ipBlock
			->method( 'getId' )
			->willReturn( 1 );

		$rangeBlock = $this->createMock( DatabaseBlock::class );
		$rangeBlock
			->method( 'getType' )
			->willReturn( Block::TYPE_RANGE );
		$rangeBlock
			->method( 'getId' )
			->willReturn( 2 );

		$autoBlock = $this->createMock( DatabaseBlock::class );
		$autoBlock
			->method( 'getType' )
			->willReturn( Block::TYPE_AUTO );
		$autoBlock
			->method( 'getId' )
			->willReturn( 3 );

		$userBlock = $this->createMock( DatabaseBlock::class );
		$userBlock
			->method( 'getType' )
			->willReturn( Block::TYPE_USER );
		$userBlock
			->method( 'getId' )
			->willReturn( 5 );

		$actions = [];
		$eventSubmitter = $this->createMock( EventSubmitter::class );
		$eventSubmitter
			->expects( $this->exactly( 3 ) )
			->method( 'submit' )
			->willReturnCallback(
				static function ( string $stream, array $event ) use ( &$actions ): void {
					$actions[] = $event['action'];
				}
			);

		$hook = new CaptchaScoreBlocksHook( $eventSubmitter, $services->get( 'EventBus.UserEntitySerializer' ) );

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			$riskScore,
			[ $ipBlock, $rangeBlock, $autoBlock, $userBlock ],
			$user,
			'',
			$request
		);

		$this->assertArrayEquals(
			[
				'ip_block_edit_attempt',
				'ip_range_block_edit_attempt',
				'auto_block_edit_attempt'
			],
			$actions
		);
	}

	public function testRiskScoreRetrievedForBlocksSubmitsEventForGlobalBlock(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalBlocking' );

		$services = $this->getServiceContainer();
		$blockId = 77;
		$riskScore = 0.6;
		$user = UserIdentityValue::newAnonymous( '1.2.3.4' );
		$request = new FauxRequest( [], true );
		RequestContext::getMain()->setRequest( $request );

		$mockGlobalBlock = $this->createMock( GlobalBlock::class );
		$mockGlobalBlock
			->method( 'getType' )
			->willReturn( Block::TYPE_IP );
		$mockGlobalBlock
			->method( 'getId' )
			->willReturn( $blockId );

		$userEntitySerializer = $services->get( 'EventBus.UserEntitySerializer' );
		$eventSubmitter = $this->createMock( EventSubmitter::class );
		$eventSubmitter->expects( $this->once() )
			->method( 'submit' )
			->with(
				'mediawiki.hcaptcha.risk_score',
				[
					'$schema' => self::SCHEMA,
					'action' => 'ip_block_edit_attempt',
					'wiki_id' => WikiMap::getCurrentWikiId(),
					'identifier' => $blockId,
					'identifier_type' => 'global_block',
					'performer' => $userEntitySerializer->toArray( $user ),
					'http' => [ 'method' => 'POST' ],
					'risk_score' => $riskScore,
					'mw_entry_point' => MW_ENTRY_POINT,
				]
			);

		$hook = new CaptchaScoreBlocksHook( $eventSubmitter, $userEntitySerializer );

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			$riskScore,
			[ $mockGlobalBlock ],
			$user,
			'',
			$request
		);
	}

	public function testRiskScoreRetrievedForBlocksSkipsUnsupportedGlobalBlockType(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalBlocking' );

		$services = $this->getServiceContainer();

		$mockGlobalBlock = $this->createMock( GlobalBlock::class );
		$mockGlobalBlock
			->method( 'getType' )
			->willReturn( Block::TYPE_USER );

		$eventSubmitter = $this->createMock( EventSubmitter::class );
		$eventSubmitter
			->expects( $this->never() )
			->method( 'submit' );

		$hook = new CaptchaScoreBlocksHook( $eventSubmitter, $services->get( 'EventBus.UserEntitySerializer' ) );

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			0.5,
			[ $mockGlobalBlock ],
			UserIdentityValue::newAnonymous( '1.2.3.4' ),
			'',
			new FauxRequest( [], true )
		);
	}

	/** @dataProvider provideAccountCreationBlockEvents */
	public function testRiskScoreRetrievedForBlocksUsesAccountCreationActions(
		int $blockType,
		?string $expectedAction
	): void {
		$services = $this->getServiceContainer();
		$blockId = 42;
		$user = UserIdentityValue::newAnonymous( '1.2.3.4' );
		$request = new FauxRequest( [], true );
		RequestContext::getMain()->setRequest( $request );
		RequestContext::getMain()->setTitle( Title::makeTitle( NS_SPECIAL, 'CreateAccount' ) );

		$mockBlock = $this->createMock( DatabaseBlock::class );
		$mockBlock->method( 'getType' )->willReturn( $blockType );
		$mockBlock->method( 'getId' )->willReturn( $blockId );

		$eventSubmitter = $this->createMock( EventSubmitter::class );
		if ( $expectedAction === null ) {
			$eventSubmitter->expects( $this->never() )->method( 'submit' );
		} else {
			$eventSubmitter->expects( $this->once() )
				->method( 'submit' )
				->with(
					'mediawiki.hcaptcha.risk_score',
					$this->callback( static fn ( array $event ) =>
						$event['action'] === $expectedAction && $event['$schema'] === self::SCHEMA
					)
				);
		}

		$hook = new CaptchaScoreBlocksHook( $eventSubmitter, $services->get( 'EventBus.UserEntitySerializer' ) );

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			0.7,
			[ $mockBlock ],
			$user,
			'',
			$request
		);
	}

	public static function provideAccountCreationBlockEvents(): array {
		return [
			'IP block' => [
				'blockType' => Block::TYPE_IP,
				'expectedAction' => 'ip_block_account_creation_attempt',
			],
			'Range block' => [
				'blockType' => Block::TYPE_RANGE,
				'expectedAction' => 'ip_range_block_account_creation_attempt',
			],
			'Auto block is not logged for account creation' => [
				'blockType' => Block::TYPE_AUTO,
				'expectedAction' => null,
			],
		];
	}
}
