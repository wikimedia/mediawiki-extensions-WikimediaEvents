<?php

declare( strict_types=1 );

namespace WikimediaEvents\Tests\Integration\EventDataProducts;

use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventBus\LinkedArtifacts\RevisionLinkedArtifactPrefetchedEvent;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikimediaEvents\EventDataProducts\RevisionHtmlFeatureCountsChangeIngress;

/**
 * @covers \WikimediaEvents\EventDataProducts\RevisionHtmlFeatureCountsChangeIngress
 * @group Database
 */
class RevisionHtmlFeatureCountsChangeIngressTest extends MediaWikiIntegrationTestCase {
	use MockWikiMapTrait;

	private const BODY = '{"wiki_id":"enwiki","revision":{"rendering":{"render_id":"a-render-id",'
		. '"features":{"software_info":{"name":"mwparserfromhtml","version":"2.1.2"},'
		. '"counts":{"reference":10,"wikilink":30,"navbox":0}}}}}';

	/** @var array[] Events sent to the feature counts stream. */
	private array $sentEvents = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mockWikiMap();

		// Send only the feature counts stream to the spy, and ignore other streams,
		// e.g. mediawiki.page_change.v1 from the edits in this test.
		$eventFactory = $this->createMock( EventFactory::class );
		$spyEventBus = $this->createNoOpMock( EventBus::class, [ 'send', 'getFactory' ] );
		$spyEventBus->method( 'getFactory' )->willReturn( $eventFactory );
		$spyEventBus->method( 'send' )->willReturnCallback( function ( array $events ) {
			$this->sentEvents = array_merge( $this->sentEvents, $events );
			return true;
		} );
		$otherEventBus = $this->createNoOpMock( EventBus::class, [ 'send', 'getFactory' ] );
		$otherEventBus->method( 'getFactory' )->willReturn( $eventFactory );

		$eventBusFactory = $this->createNoOpMock( EventBusFactory::class, [ 'getInstanceForStream' ] );
		$eventBusFactory->method( 'getInstanceForStream' )->willReturnCallback(
			static fn ( string $stream ) =>
				$stream === RevisionHtmlFeatureCountsChangeIngress::STREAM_NAME_DEFAULT
					? $spyEventBus
					: $otherEventBus
		);
		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );
	}

	private function dispatch( int $pageId, int $revisionId ): void {
		$services = $this->getServiceContainer();
		$services->getDomainEventDispatcher()->dispatch(
			new RevisionLinkedArtifactPrefetchedEvent(
				RevisionHtmlFeatureCountsChangeIngress::ARTIFACT_NAME,
				WikiMap::getCurrentWikiId(),
				$pageId,
				$revisionId,
				'application/json',
				self::BODY,
				new ConvertibleTimestamp(),
				null,
				triggerEventType: 'PageLatestRevisionChanged',
				triggerCause: 'edit'
			),
			$services->getConnectionProvider()
		);
		$this->runDeferredUpdates();
	}

	private function createRevision( string $title ): RevisionRecord {
		return $this->editPage( $title, 'content', 'An edit summary' )->getNewRevision();
	}

	public function testProducesAnEventForAPrefetchedRevision(): void {
		$revision = $this->createRevision( __METHOD__ );

		$this->dispatch( $revision->getPageId(), $revision->getId() );

		$this->assertCount( 1, $this->sentEvents );
		$event = $this->sentEvents[0];
		$this->assertSame( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
		// Since core de09263, WikiMap gets the current wiki from $wgCanonicalServer, not from the mocked SiteLookup.
		$this->assertSame(
			WikiMap::getWiki( WikiMap::getCurrentWikiId() )->getDisplayName(),
			$event['meta']['domain']
		);
		$this->assertSame( RevisionHtmlFeatureCountsChangeIngress::STREAM_NAME_DEFAULT, $event['meta']['stream'] );
		$this->assertSame( $revision->getPageId(), $event['page']['page_id'] );
		$this->assertSame( $revision->getId(), $event['revision']['rev_id'] );
		$this->assertArrayHasKey( 'rev_dt', $event['revision'] );
		$this->assertSame( 'An edit summary', $event['revision']['comment'] );
		$this->assertSame(
			[ 'reference' => 10, 'wikilink' => 30, 'navbox' => 0 ],
			$event['revision']['rendering']['features']['counts']
		);
		$this->assertSame( 'a-render-id', $event['revision']['rendering']['render_id'] );
		$this->assertSame( [ 'cause' => 'edit', 'is_reconciliation_request' => false ], $event['provenance'] );
	}

	public function testProducesNoEventForADeletedPage(): void {
		$revision = $this->createRevision( __METHOD__ );
		$this->deletePage( $this->getServiceContainer()->getWikiPageFactory()->newFromID( $revision->getPageId() ) );

		$this->dispatch( $revision->getPageId(), $revision->getId() );

		$this->assertSame( [], $this->sentEvents );
	}
}
