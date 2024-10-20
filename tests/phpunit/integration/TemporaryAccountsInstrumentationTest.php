<?php
namespace WikimediaEvents\Tests\Integration;

use Closure;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Stats\Metrics\CounterMetric;
use WikimediaEvents\TemporaryAccountsInstrumentation;

/**
 * @group Database
 * @covers \WikimediaEvents\TemporaryAccountsInstrumentation
 */
class TemporaryAccountsInstrumentationTest extends MediaWikiIntegrationTestCase {

	use TempUserTestTrait;

	public function testShouldTrackPageDeletionRate(): void {
		$page = $this->getExistingTestPage();

		$this->deletePage( $page );

		$this->assertCounterIncremented( 'users_page_delete_total' );
	}

	/**
	 * @dataProvider provideUsers
	 */
	public function testShouldTrackRollbacksForEdits( Closure $userProvider, string $userType ): void {
		$this->enableAutoCreateTempUser();

		$user = $userProvider->call( $this );

		$page = $this->getExistingTestPage();
		$this->editPage( $page, 'test', '', NS_MAIN, $user );

		$status = $this->getServiceContainer()
			->getRollbackPageFactory()
			->newRollbackPage( $page, $this->getTestSysop()->getAuthority(), $user )
			->rollback();

		$this->assertStatusGood( $status );
		$this->assertCounterIncremented( 'user_revert_total', [ $userType ] );
	}

	/**
	 * @dataProvider provideUsers
	 */
	public function testShouldTrackRevertsForTemporaryOrAnonymousUserEdits(
		Closure $userProvider,
		string $userType
	): void {
		$this->enableAutoCreateTempUser();

		$user = $userProvider->call( $this );

		$page = $this->getExistingTestPage();
		$prevRev = $page->getRevisionRecord();

		$pageUpdateStatus = $this->editPage( $page, 'test', '', NS_MAIN, $user );

		$this->getServiceContainer()
			->getPageUpdaterFactory()
			->newPageUpdater( $page, $this->getTestSysop()->getUserIdentity() )
			->setContent( SlotRecord::MAIN, $prevRev->getContent( SlotRecord::MAIN ) )
			->markAsRevert(
				EditResult::REVERT_UNDO,
				$pageUpdateStatus->getNewRevision()->getId(),
				$prevRev->getId()
			)
			->saveRevision( CommentStoreComment::newUnsavedComment( 'test' ) );

		$this->assertCounterIncremented( 'user_revert_total', [ $userType ] );
	}

	/**
	 * @dataProvider provideUsers
	 */
	public function testShouldNotTrackNonRevertEdits( Closure $userProvider ): void {
		$this->enableAutoCreateTempUser();

		$user = $userProvider->call( $this );

		$page = $this->getExistingTestPage();

		$this->editPage( $page, 'test', '', NS_MAIN, $user );

		$newContent = $this->getServiceContainer()
			->getContentHandlerFactory()
			->getContentHandler( CONTENT_MODEL_WIKITEXT )
			->unserializeContent( 'new content' );

		$this->getServiceContainer()
			->getPageUpdaterFactory()
			->newPageUpdater( $page, $this->getTestSysop()->getUserIdentity() )
			->setContent( SlotRecord::MAIN, $newContent )
			->saveRevision( CommentStoreComment::newUnsavedComment( 'test' ) );

		$this->assertCounterNotIncremented( 'user_revert_total' );
	}

	public static function provideUsers(): iterable {
		// phpcs:disable Squiz.Scope.StaticThisUsage.Found
		yield 'anonymous user' => [
			function (): User {
				$this->disableAutoCreateTempUser();
				return $this->getServiceContainer()
					->getUserFactory()
					->newAnonymous( '127.0.0.1' );
			},
			TemporaryAccountsInstrumentation::ACCOUNT_TYPE_ANON
		];
		yield 'temporary user' => [
			function (): User {
				$this->enableAutoCreateTempUser();

				$req = new FauxRequest();
				return $this->getServiceContainer()
					->getTempUserCreator()
					->create( null, $req )
					->getUser();
			},
			TemporaryAccountsInstrumentation::ACCOUNT_TYPE_TEMPORARY
		];
		yield 'registered user' => [
			fn () => $this->getTestUser()->getUser(),
			TemporaryAccountsInstrumentation::ACCOUNT_TYPE_NORMAL
		];
		// phpcs:enable
	}

	public function testBlockIncrementIpUser() {
		$userFactory = $this->getServiceContainer()->getUserFactory();
		$ipUser = $userFactory->newAnonymous( '1.2.3.4' );
		$blockUserFactory = $this->getServiceContainer()->getBlockUserFactory();
		$block = $blockUserFactory->newBlockUser(
			$ipUser,
			$this->getTestSysop()->getAuthority(),
			'infinity',
		);
		$block->placeBlock();
		DeferredUpdates::doUpdates();
		$this->assertCounterIncremented( 'block_target_total', [ 'anon' ] );
	}

	public function testBlockIncrementIpRangeUser() {
		$blockUserFactory = $this->getServiceContainer()->getBlockUserFactory();
		$block = $blockUserFactory->newBlockUser(
			'1.2.3.0/24',
			$this->getTestSysop()->getAuthority(),
			'infinity',
		);
		$block->placeBlock();
		DeferredUpdates::doUpdates();
		$this->assertCounterIncremented( 'block_target_total', [ 'iprange' ] );
	}

	public function testBlockIncrementTempUser() {
		$tempUser = $this->getServiceContainer()->getTempUserCreator()->create( '~2024-1', new FauxRequest() );
		$blockUserFactory = $this->getServiceContainer()->getBlockUserFactory();
		$block = $blockUserFactory->newBlockUser(
			$tempUser->getUser(),
			$this->getTestSysop()->getAuthority(),
			'infinity'
		);
		$block->placeBlock();
		DeferredUpdates::doUpdates();
		$this->assertCounterIncremented( 'block_target_total', [ 'temp' ] );
	}

	public function testBlockIncrementNamedUser() {
		$namedUser = $this->getTestUser()->getUser();
		$blockUserFactory = $this->getServiceContainer()->getBlockUserFactory();
		$block = $blockUserFactory->newBlockUser(
			$namedUser,
			$this->getTestSysop()->getAuthority(),
			'infinity'
		);
		$block->placeBlock();
		DeferredUpdates::doUpdates();
		$this->assertCounterIncremented( 'block_target_total', [ 'normal' ] );
	}

	/**
	 * Convenience function to assert that the per-wiki counter with the given name
	 * was incremented exactly once.
	 *
	 * @param string $metricName The name of the metric, without the component.
	 * @param string[] $expectedLabels Optional list of additional expected label values.
	 *
	 * @return void
	 */
	private function assertCounterIncremented( string $metricName, array $expectedLabels = [] ): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'WikimediaEvents' )
			->getCounter( $metricName );

		$samples = $metric->getSamples();

		$this->assertInstanceOf( CounterMetric::class, $metric );
		$this->assertSame( 1, $metric->getSampleCount() );
		$this->assertSame( 1.0, $samples[0]->getValue() );

		$wikiId = WikiMap::getCurrentWikiId();
		$expectedLabels = array_merge(
			[ strtr( $wikiId, [ '_' => '', '-' => '_' ] ) ],
			$expectedLabels
		);

		$this->assertSame( $expectedLabels, $samples[0]->getLabelValues() );
	}

	/**
	 * Convenience function to assert that the counter with the given name was not incremented.
	 * @param string $metricName
	 * @return void
	 */
	private function assertCounterNotIncremented( string $metricName ): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'WikimediaEvents' )
			->getCounter( $metricName );

		$this->assertInstanceOf( CounterMetric::class, $metric );
		$this->assertSame( 0, $metric->getSampleCount() );
	}
}
