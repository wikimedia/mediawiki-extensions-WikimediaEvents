<?php

namespace WikimediaEvents\Tests\Integration\EditPage;

use MediaWiki\Block\Block;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Extension\GlobalBlocking\GlobalBlock;
use MediaWiki\Extension\GlobalBlocking\Services\GlobalBlockLookup;
use MediaWiki\Request\FauxRequest;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use WikimediaEvents\EditPage\CaptchaScoreBlocksHook;

/**
 * @covers \WikimediaEvents\EditPage\CaptchaScoreBlocksHook
 * @group Database
 */
class CaptchaScoreBlocksHookTest extends MediaWikiIntegrationTestCase {

	private const string SCHEMA = '/analytics/mediawiki/hcaptcha/risk_score/1.4.0';

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

		$mockBlockStore = $this->createMock( DatabaseBlockStore::class );
		$mockBlockStore
			->expects( $this->once() )
			->method( 'newFromID' )
			->with( $blockId )
			->willReturn( $mockBlock );

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

		$hook = new CaptchaScoreBlocksHook(
			$eventSubmitter,
			$userEntitySerializer,
			$mockBlockStore,
			null,
		);

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			$riskScore,
			[ $blockId ],
			[],
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

		$mockBlockStore = $this->createMock( DatabaseBlockStore::class );
		$mockBlockStore->method( 'newFromID' )
			->with( $blockId )
			->willReturn( $mockBlock );

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

		$hook = new CaptchaScoreBlocksHook(
			$eventSubmitter,
			$userEntitySerializer,
			$mockBlockStore,
			null,
		);

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			$riskScore,
			[ $blockId ],
			[],
			$user,
			'abc123',
			$request
		);
	}

	public function testRiskScoreRetrievedForBlocksSkipsBlockNotFound(): void {
		$services = $this->getServiceContainer();

		$mockBlockStore = $this->createMock( DatabaseBlockStore::class );
		$mockBlockStore
			->method( 'newFromID' )
			->willReturn( null );

		$eventSubmitter = $this->createMock( EventSubmitter::class );
		$eventSubmitter
			->expects( $this->never() )
			->method( 'submit' );

		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger
			->expects( $this->once() )
			->method( 'warning' )
			->with(
				'Local block {blockId} not found when collecting hCaptcha risk scores',
				[ 'blockId' => 99 ]
			);
		$this->setLogger( 'WikimediaEvents', $mockLogger );

		$hook = new CaptchaScoreBlocksHook(
			$eventSubmitter,
			$services->get( 'EventBus.UserEntitySerializer' ),
			$mockBlockStore,
			null,
		);

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			0.5,
			[ 99 ],
			[],
			UserIdentityValue::newAnonymous( '1.2.3.4' ),
			'',
			new FauxRequest( [], true )
		);
	}

	public function testRiskScoreRetrievedForBlocksSkipsUnsupportedBlockType(): void {
		$services = $this->getServiceContainer();

		$mockBlock = $this->createMock( DatabaseBlock::class );
		$mockBlock
			->method( 'getType' )
			->willReturn( Block::TYPE_USER );

		$mockBlockStore = $this->createMock( DatabaseBlockStore::class );
		$mockBlockStore
			->method( 'newFromID' )
			->willReturn( $mockBlock );

		$eventSubmitter = $this->createMock( EventSubmitter::class );
		$eventSubmitter
			->expects( $this->never() )
			->method( 'submit' );

		$hook = new CaptchaScoreBlocksHook(
			$eventSubmitter,
			$services->get( 'EventBus.UserEntitySerializer' ),
			$mockBlockStore,
			null,
		);

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			0.5,
			[ 1 ],
			[],
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

		$mockBlockStore = $this->createMock( DatabaseBlockStore::class );
		$mockBlockStore->method( 'newFromID' )
			->willReturnCallback( static fn ( int $id ) => match ( $id ) {
				1 => $ipBlock,
				2 => $rangeBlock,
				3 => $autoBlock,
				5 => $userBlock,
				default => null,
			} );

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

		$hook = new CaptchaScoreBlocksHook(
			$eventSubmitter,
			$services->get( 'EventBus.UserEntitySerializer' ),
			$mockBlockStore,
			null,
		);

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			$riskScore,
			[ 1, 2, 3, 4, 5 ],
			[],
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

		$mockGlobalBlockLookup = $this->createMock( GlobalBlockLookup::class );
		$mockGlobalBlockLookup->method( 'newFromId' )
			->with( $blockId )
			->willReturn( $mockGlobalBlock );

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

		$hook = new CaptchaScoreBlocksHook(
			$eventSubmitter,
			$userEntitySerializer,
			$this->createMock( DatabaseBlockStore::class ),
			$mockGlobalBlockLookup,
		);

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			$riskScore,
			[],
			[ $blockId ],
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

		$mockGlobalBlockLookup = $this->createMock( GlobalBlockLookup::class );
		$mockGlobalBlockLookup
			->method( 'newFromId' )
			->willReturn( $mockGlobalBlock );

		$eventSubmitter = $this->createMock( EventSubmitter::class );
		$eventSubmitter
			->expects( $this->never() )
			->method( 'submit' );

		$hook = new CaptchaScoreBlocksHook(
			$eventSubmitter,
			$services->get( 'EventBus.UserEntitySerializer' ),
			$this->createMock( DatabaseBlockStore::class ),
			$mockGlobalBlockLookup,
		);

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			0.5,
			[],
			[ 1 ],
			UserIdentityValue::newAnonymous( '1.2.3.4' ),
			'',
			new FauxRequest( [], true )
		);
	}

	public function testRiskScoreRetrievedForBlocksSkipsGlobalBlockNotFound(): void {
		$services = $this->getServiceContainer();

		$mockGlobalBlockLookup = $this->createMock( GlobalBlockLookup::class );
		$mockGlobalBlockLookup
			->method( 'newFromId' )
			->willReturn( null );

		$eventSubmitter = $this->createMock( EventSubmitter::class );
		$eventSubmitter
			->expects( $this->never() )
			->method( 'submit' );

		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger
			->expects( $this->once() )
			->method( 'warning' )
			->with(
				'Global block {blockId} not found when collecting hCaptcha risk scores',
				[ 'blockId' => 99 ]
			);
		$this->setLogger( 'WikimediaEvents', $mockLogger );

		$hook = new CaptchaScoreBlocksHook(
			$eventSubmitter,
			$services->get( 'EventBus.UserEntitySerializer' ),
			$this->createMock( DatabaseBlockStore::class ),
			$mockGlobalBlockLookup,
		);

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			0.5,
			[],
			[ 99 ],
			UserIdentityValue::newAnonymous( '1.2.3.4' ),
			'',
			new FauxRequest( [], true )
		);
	}

	public function testRiskScoreRetrievedForBlocksSkipsGlobalBlocksWhenLookupNotAvailable(): void {
		$services = $this->getServiceContainer();

		$eventSubmitter = $this->createMock( EventSubmitter::class );
		$eventSubmitter->expects( $this->never() )->method( 'submit' );

		// globalBlockLookup is null (GlobalBlocking extension not installed)
		$hook = new CaptchaScoreBlocksHook(
			$eventSubmitter,
			$services->get( 'EventBus.UserEntitySerializer' ),
			$this->createMock( DatabaseBlockStore::class ),
			null,
		);

		$hook->onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
			0.5,
			[],
			[ 1, 2, 3 ],
			UserIdentityValue::newAnonymous( '1.2.3.4' ),
			'',
			new FauxRequest( [], true )
		);
	}
}
