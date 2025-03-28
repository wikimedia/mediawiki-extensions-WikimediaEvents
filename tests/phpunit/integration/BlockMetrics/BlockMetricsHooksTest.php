<?php

namespace WikimediaEvents\Tests;

use MediaWiki\Api\ApiMessage;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\BlockMetrics\BlockMetricsHooks;

/**
 * @covers \WikimediaEvents\BlockMetrics\BlockMetricsHooks
 * @group Database
 */
class BlockMetricsHooksTest extends MediaWikiIntegrationTestCase {

	public function testOnPermissionStatusAudit() {
		$this->markTestSkippedIfExtensionNotLoaded( 'EventBus' );

		$user = $this->getMutableTestUser()->getUser();
		$admin = $this->getTestSysop()->getUser();
		$status = $this->getServiceContainer()->getBlockUserFactory()
			->newBlockUser( $user, $admin, 'infinity', '', [ 'isCreateAccountBlocked' => true ] )
			->placeBlockUnsafe();
		$this->assertStatusGood( $status );
		/** @var DatabaseBlock $block */
		$block = $status->getValue();

		$permissionStatus = PermissionStatus::newEmpty();
		$permissionStatus->fatal( ApiMessage::create( 'blockedtext', 'blocked' ) );
		$permissionStatus->setBlock( $block );

		$userFactory = $this->getServiceContainer()->getUserFactory();
		$eventFactory = $this->getServiceContainer()->get( 'EventBus.EventFactory' );
		$blockMetricsHooks = $this->getMockBuilder( BlockMetricsHooks::class )
			->setConstructorArgs( [ $userFactory, $eventFactory ] )
			->onlyMethods( [ 'submitEvent' ] )
			->getMock();
		$blockMetricsHooks->expects( $this->once() )
			->method( 'submitEvent' )
			->willReturnCallback( function ( string $streamName, array $event ) use ( $block, $user ) {
				$this->assertSame( 'mediawiki.accountcreation_block', $streamName );
				$this->assertArraySubmapSame(
					[
						'$schema' => BlockMetricsHooks::SCHEMA,
						'database' => $this->getDb()->getDBname(),
						'block_id' => json_encode( $block->getId() ),
						'block_type' => 'user',
						'block_expiry' => 'infinity',
						'block_scope' => 'local',
						'error_message_keys' => [ 'blockedtext' ],
						'performer' => [
							'user_text' => $user->getName(),
							'user_id' => $user->getId(),
							'user_groups' => [ '*', 'user' ],
							'user_is_bot' => false,
							'user_edit_count' => 0,
						],
						'user_ip' => '127.0.0.1',
					],
					$event
				);
			} );
		$blockMetricsHooks->onPermissionStatusAudit( Title::newMainPage(), $user, 'createaccount',
			PermissionManager::RIGOR_SECURE, $permissionStatus );
	}

}
