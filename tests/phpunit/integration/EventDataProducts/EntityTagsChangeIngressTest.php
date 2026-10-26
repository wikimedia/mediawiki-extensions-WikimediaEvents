<?php

declare( strict_types=1 );

namespace WikimediaEvents\Tests\Integration\EventDataProducts;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\ChangeTags\Event\RevisionTagsUpdatedEvent;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Page\PageLookup;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\Platform\ISQLPlatform;
use WikimediaEvents\EventDataProducts\EntityTagsChangeEventSerializer;
use WikimediaEvents\EventDataProducts\EntityTagsChangeIngress;

/**
 * @covers \WikimediaEvents\EventDataProducts\EntityTagsChangeIngress
 * @group Database
 */
class EntityTagsChangeIngressTest extends MediaWikiIntegrationTestCase {

	private const REVISION_STREAM_NAME = EntityTagsChangeIngress::REVISION_TAGS_CHANGE_STREAM_NAME_DEFAULT;
	private const LOG_STREAM_NAME = EntityTagsChangeIngress::LOG_TAGS_CHANGE_STREAM_NAME_DEFAULT;

	private const PUBLIC_TAG = 'test-tag';
	// Tags with the prefix mw-private- are private. See ChangeTagsStore::isRestrictedTag().
	private const PRIVATE_TAG = 'mw-private-test-tag';

	/**
	 * Map of stream name to the events that were sent to that stream.
	 * @var array<string,array[]>
	 */
	private array $sentEvents = [];

	private ChangeTagsStore $changeTagsStore;

	protected function setUp(): void {
		parent::setUp();

		// Record the events of all streams by their meta.stream value.
		// EventBusSendUpdate sends the events of more than one stream in one call.
		$eventBus = $this->createNoOpMock( EventBus::class, [ 'send', 'getFactory' ] );
		// The legacy EventBus hook handlers call EventBus::getFactory().
		$eventBus->method( 'getFactory' )->willReturn( $this->createMock( EventFactory::class ) );
		$eventBus->method( 'send' )->willReturnCallback( function ( array $events ): bool {
			foreach ( $events as $event ) {
				// The mocked EventFactory of the legacy hook handlers makes events without meta.stream.
				if ( is_array( $event ) && isset( $event['meta']['stream'] ) ) {
					$this->sentEvents[$event['meta']['stream']][] = $event;
				}
			}
			return true;
		} );

		// The legacy EventBus hook handlers call getInstanceForStream().
		// EventBusSendUpdate calls getEventServiceNameForStream() and getInstance().
		$eventBusFactory = $this->createNoOpMock(
			EventBusFactory::class,
			[ 'getInstanceForStream', 'getEventServiceNameForStream', 'getInstance' ]
		);
		$eventBusFactory->method( 'getInstanceForStream' )->willReturn( $eventBus );
		$eventBusFactory->method( 'getEventServiceNameForStream' )->willReturn( 'test-event-service' );
		$eventBusFactory->method( 'getInstance' )->willReturn( $eventBus );
		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );

		$this->changeTagsStore = $this->getServiceContainer()->getChangeTagsStore();
	}

	/**
	 * Returns the events that were sent to the stream.
	 */
	private function getSentEvents( string $streamName ): array {
		return $this->sentEvents[$streamName] ?? [];
	}

	/**
	 * Runs the deferred updates. Then removes the events that were sent until now.
	 */
	private function flushSentEvents(): void {
		$this->runDeferredUpdates();
		$this->sentEvents = [];
	}

	/**
	 * Removes all tags. Then the tags before the next update are known.
	 */
	private function emptyChangeTagsTables(): void {
		$dbw = $this->getDb();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'change_tag' )
			->where( ISQLPlatform::ALL_ROWS )
			->execute();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'change_tag_def' )
			->where( ISQLPlatform::ALL_ROWS )
			->execute();
	}

	/**
	 * Makes a page with one revision that has no tags.
	 *
	 * @return int The ID of the revision
	 */
	private function makeUntaggedRevision( string $titleText ): int {
		$wikiPage = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), $titleText )
		);
		$this->flushSentEvents();
		// The page creation can add tags.
		$this->emptyChangeTagsTables();

		return $wikiPage->getRevisionRecord()->getId();
	}

	/**
	 * Makes a log entry that has no tags and no associated revision.
	 *
	 * @return int The ID of the log entry
	 */
	private function makeUntaggedLogEntry(): int {
		$logEntry = new ManualLogEntry( 'delete', 'delete' );
		$logEntry->setPerformer( $this->getTestUser()->getUserIdentity() );
		$logEntry->setTarget( $this->getExistingTestPage()->getTitle() );
		$logEntry->setComment( 'testing' );
		$logId = $logEntry->insert( $this->getDb() );

		$this->flushSentEvents();
		$this->emptyChangeTagsTables();

		return $logId;
	}

	/**
	 * Changes the tags of a revision. Then runs the deferred updates.
	 */
	private function updateRevisionTags(
		int $revId,
		array $tagsToAdd,
		array $tagsToRemove = [],
		?string $params = null
	): void {
		$rcId = null;
		$logId = null;
		$this->changeTagsStore->updateTags( $tagsToAdd, $tagsToRemove, $rcId, $revId, $logId, $params );
		$this->runDeferredUpdates();
	}

	/**
	 * Changes the tags of a log entry. Then runs the deferred updates.
	 */
	private function updateLogTags( int $logId, array $tagsToAdd, array $tagsToRemove = [] ): void {
		$rcId = null;
		$revId = null;
		$this->changeTagsStore->updateTags( $tagsToAdd, $tagsToRemove, $rcId, $revId, $logId );
		$this->runDeferredUpdates();
	}

	public function testRevisionTagsChangeIsEmitted(): void {
		$revId = $this->makeUntaggedRevision( 'TestRevisionTagsChange' );
		$performer = $this->getTestSysop()->getUserIdentity();

		$rcId = null;
		$logId = null;
		$this->changeTagsStore->updateTags(
			[ self::PUBLIC_TAG ], [], $rcId, $revId, $logId, 'test params', null, $performer
		);
		$this->runDeferredUpdates();

		$events = $this->getSentEvents( self::REVISION_STREAM_NAME );
		$this->assertCount( 1, $events );
		$event = $events[0];

		$this->assertSame( EntityTagsChangeEventSerializer::REVISION_TAGS_CHANGE_SCHEMA_URI, $event['$schema'] );
		$this->assertSame( self::REVISION_STREAM_NAME, $event['meta']['stream'] );
		$this->assertSame( $revId, $event['revision']['rev_id'] );
		$this->assertSame(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'TestRevisionTagsChange' )->getPrefixedDBkey(),
			$event['page']['page_title']
		);
		$this->assertFalse( $event['page']['is_redirect'] );
		$this->assertSame( [ self::PUBLIC_TAG ], array_column( $event['tags'], 'tag_name' ) );
		$this->assertSame( 'test params', $event['tags'][0]['params'] );
		$this->assertSame( [], $event['prior_state']['tags'] );
		$this->assertSame( $performer->getName(), $event['performer']['user_text'] );
	}

	/**
	 * The ingress gets the page as a PageRecord. This gives the correct page.is_redirect value.
	 */
	public function testRevisionTagsChangeOfRedirectHasIsRedirect(): void {
		$wikiPage = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'TestRedirectTagsChange' )
		);
		$revId = $this->editPage( $wikiPage, '#REDIRECT [[Redirect target]]' )
			->getNewRevision()
			->getId();
		$this->flushSentEvents();
		// The edit adds the mw-new-redirect tag.
		$this->emptyChangeTagsTables();

		$this->updateRevisionTags( $revId, [ self::PUBLIC_TAG ] );

		$events = $this->getSentEvents( self::REVISION_STREAM_NAME );
		$this->assertCount( 1, $events );
		$this->assertTrue( $events[0]['page']['is_redirect'] );
	}

	public function testRemovedRevisionTagIsEmitted(): void {
		$revId = $this->makeUntaggedRevision( 'TestRevisionTagRemoved' );
		$this->updateRevisionTags( $revId, [ self::PUBLIC_TAG ] );
		$this->sentEvents = [];

		$this->updateRevisionTags( $revId, [], [ self::PUBLIC_TAG ] );

		$events = $this->getSentEvents( self::REVISION_STREAM_NAME );
		$this->assertCount( 1, $events );
		$this->assertSame( [], $events[0]['tags'] );
		$this->assertSame( [ self::PUBLIC_TAG ], array_column( $events[0]['prior_state']['tags'], 'tag_name' ) );
	}

	public function testLogTagsChangeIsEmitted(): void {
		$logId = $this->makeUntaggedLogEntry();

		$this->updateLogTags( $logId, [ self::PUBLIC_TAG ] );

		$events = $this->getSentEvents( self::LOG_STREAM_NAME );
		$this->assertCount( 1, $events );
		$event = $events[0];

		$this->assertSame( EntityTagsChangeEventSerializer::LOG_TAGS_CHANGE_SCHEMA_URI, $event['$schema'] );
		$this->assertSame( self::LOG_STREAM_NAME, $event['meta']['stream'] );
		$this->assertSame( [ 'log_id' => $logId ], $event['log'] );
		$this->assertSame( [ self::PUBLIC_TAG ], array_column( $event['tags'], 'tag_name' ) );
		$this->assertSame( [], $event['prior_state']['tags'] );
		// updateTags() did not get a performer.
		$this->assertArrayNotHasKey( 'performer', $event );

		// The log entry has no associated revision.
		$this->assertSame( [], $this->getSentEvents( self::REVISION_STREAM_NAME ) );
	}

	/**
	 * If an update changes only private tags, the ingress does not send an event.
	 */
	public function testRevisionTagsChangeIsNotEmittedIfOnlyPrivateTagsChange(): void {
		$revId = $this->makeUntaggedRevision( 'TestRevisionPrivateTagsChange' );

		$this->updateRevisionTags( $revId, [ self::PRIVATE_TAG ] );

		$this->assertSame( [], $this->getSentEvents( self::REVISION_STREAM_NAME ) );
		// The update also tags the page creation log entry of the revision.
		$this->assertSame( [], $this->getSentEvents( self::LOG_STREAM_NAME ) );
	}

	public function testLogTagsChangeIsNotEmittedIfOnlyPrivateTagsChange(): void {
		$logId = $this->makeUntaggedLogEntry();

		$this->updateLogTags( $logId, [ self::PRIVATE_TAG ] );

		$this->assertSame( [], $this->getSentEvents( self::LOG_STREAM_NAME ) );
	}

	/**
	 * If an update changes public and private tags, the event contains only the public tags.
	 */
	public function testRevisionTagsChangeDoesNotContainPrivateTags(): void {
		$revId = $this->makeUntaggedRevision( 'TestRevisionMixedTagsChange' );
		$this->updateRevisionTags( $revId, [ self::PRIVATE_TAG ] );
		$this->sentEvents = [];

		$this->updateRevisionTags( $revId, [ self::PUBLIC_TAG ], [ self::PRIVATE_TAG ] );

		$events = $this->getSentEvents( self::REVISION_STREAM_NAME );
		$this->assertCount( 1, $events );
		$this->assertSame( [ self::PUBLIC_TAG ], array_column( $events[0]['tags'], 'tag_name' ) );
		$this->assertSame( [], $events[0]['prior_state']['tags'] );
	}

	public function testLogTagsChangeDoesNotContainPrivateTags(): void {
		$logId = $this->makeUntaggedLogEntry();

		$this->updateLogTags( $logId, [ self::PUBLIC_TAG, self::PRIVATE_TAG ] );

		$events = $this->getSentEvents( self::LOG_STREAM_NAME );
		$this->assertCount( 1, $events );
		$this->assertSame( [ self::PUBLIC_TAG ], array_column( $events[0]['tags'], 'tag_name' ) );
	}

	/**
	 * If the page of the revision does not exist, the ingress does not send an event.
	 * The page can be deleted after the tags update and before the handler runs.
	 */
	public function testRevisionTagsChangeIsNotEmittedIfPageIsMissing(): void {
		$revisionRecord = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'TestMissingPageTagsChange' )
		)->getRevisionRecord();
		$this->flushSentEvents();

		$pageLookup = $this->createMock( PageLookup::class );
		$pageLookup->method( 'getPageById' )->willReturn( null );

		$services = $this->getServiceContainer();
		$ingress = new EntityTagsChangeIngress(
			$services->get( 'EventBus.EventBusFactory' ),
			$services->get( 'EventBus.StreamNameMapper' ),
			$services->get( 'EventBus.EventSerializer' ),
			$services->get( 'EventBus.PageEntitySerializer' ),
			$services->get( 'EventBus.RevisionEntitySerializer' ),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$services->getTitleFactory(),
			$this->changeTagsStore,
			$pageLookup
		);

		$ingress->handleRevisionTagsUpdatedEvent( new RevisionTagsUpdatedEvent(
			$revisionRecord,
			[],
			[ self::PUBLIC_TAG => [ 'id' => 1, 'params' => null ] ]
		) );
		$this->runDeferredUpdates();

		$this->assertSame( [], $this->getSentEvents( self::REVISION_STREAM_NAME ) );
	}

}
