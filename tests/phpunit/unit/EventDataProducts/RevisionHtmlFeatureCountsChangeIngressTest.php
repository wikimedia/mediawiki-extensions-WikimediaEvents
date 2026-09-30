<?php

declare( strict_types=1 );

namespace WikimediaEvents\Tests\Unit\EventDataProducts;

use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\LinkedArtifacts\RevisionLinkedArtifactPrefetchedEvent;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageLookup;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiUnitTestCase;
use TypeError;
use UnexpectedValueException;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikimediaEvents\EventDataProducts\RevisionHtmlFeatureCountsChangeIngress;

/**
 * @covers \WikimediaEvents\EventDataProducts\RevisionHtmlFeatureCountsChangeIngress
 */
class RevisionHtmlFeatureCountsChangeIngressTest extends MediaWikiUnitTestCase {

	private const MAPPED_STREAM = 'mediawiki.revision_html_feature_counts_change.rc0';
	private const PAGE_ID = 42;
	private const REVISION_ID = 99;

	private const SOFTWARE_INFO = [ 'name' => 'mwparserfromhtml', 'version' => '2.1.2' ];
	private const COUNTS = [ 'reference' => 10, 'navbox' => 0 ];
	private const BODY = [
		// The page and revision fields are not used. The event gets them from the database.
		'wiki_id' => 'enwiki',
		'page' => [ 'page_id' => self::PAGE_ID ],
		'revision' => [
			'rev_id' => self::REVISION_ID,
			'rendering' => [
				'render_id' => 'a-render-id',
				'render_dt' => '2026-09-29T19:02:50Z',
				'content' => [
					'content_length' => 43807,
					'content_type' => 'text/html',
					'content_language' => 'en',
					'content_uri' => 'https://en.wikipedia.org/w/rest.php/v1/revision/99/html',
				],
				'features' => [
					'software_info' => self::SOFTWARE_INFO,
					'counts' => self::COUNTS,
				],
			],
		],
	];

	/**
	 * An artifact body with only revision.rendering.features.
	 */
	private static function newBody( array $features ): array {
		return [ 'revision' => [ 'rendering' => [ 'features' => $features ] ] ];
	}

	private static function newEvent(
		array|string $body = self::BODY,
		string $artifactName = RevisionHtmlFeatureCountsChangeIngress::ARTIFACT_NAME,
		bool $isReconciliationRequest = false,
		?string $triggerEventType = 'PageLatestRevisionChanged'
	): RevisionLinkedArtifactPrefetchedEvent {
		return new RevisionLinkedArtifactPrefetchedEvent(
			$artifactName,
			'enwiki',
			self::PAGE_ID,
			self::REVISION_ID,
			'application/json',
			is_string( $body ) ? $body : json_encode( $body ),
			new ConvertibleTimestamp( '20260930130000' ),
			new ConvertibleTimestamp( '20260930120005' ),
			// Named, so that the test does not depend on the order of these optional parameters.
			isReconciliationRequest: $isReconciliationRequest,
			triggerEventType: $triggerEventType,
			triggerCause: 'edit'
		);
	}

	private function newPage(): ExistingPageRecord {
		$page = $this->createMock( ExistingPageRecord::class );
		$page->method( 'getWikiId' )->willReturn( 'enwiki' );
		return $page;
	}

	private function newRevision( int $pageId = self::PAGE_ID, int $visibility = 0 ): RevisionRecord {
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getId' )->willReturn( self::REVISION_ID );
		$revision->method( 'getPageId' )->willReturn( $pageId );
		$revision->method( 'getTimestamp' )->willReturn( '20260809034011' );
		$revision->method( 'getVisibility' )->willReturn( $visibility );
		$revision->method( 'isDeleted' )->willReturnCallback(
			static fn ( int $field ): bool => ( $visibility & $field ) === $field
		);
		return $revision;
	}

	/**
	 * @param EventBus $eventBus
	 * @param PageLookup|null $pageLookup Defaults to a lookup that finds the page.
	 * @param RevisionLookup|null $revisionLookup Defaults to a lookup that finds the revision.
	 */
	private function newIngress(
		EventBus $eventBus,
		?PageLookup $pageLookup = null,
		?RevisionLookup $revisionLookup = null
	): RevisionHtmlFeatureCountsChangeIngress {
		$eventBusFactory = $this->createMock( EventBusFactory::class );
		$eventBusFactory->method( 'getInstanceForStream' )
			->with( self::MAPPED_STREAM )
			->willReturn( $eventBus );

		$streamNameMapper = $this->createMock( StreamNameMapper::class );
		$streamNameMapper->method( 'resolve' )
			->with( RevisionHtmlFeatureCountsChangeIngress::STREAM_NAME_DEFAULT )
			->willReturn( self::MAPPED_STREAM );

		// Return only the event attributes, so that WikiMap is not used.
		$eventSerializer = $this->createMock( EventSerializer::class );
		$eventSerializer->method( 'createEvent' )->willReturnCallback(
			static fn ( $schema, $stream, $uri, array $eventAttrs ): array => $eventAttrs
		);

		$pageEntitySerializer = $this->createMock( PageEntitySerializer::class );
		$pageEntitySerializer->method( 'toArray' )->willReturn( [ 'page_id' => self::PAGE_ID ] );

		if ( !$pageLookup ) {
			$pageLookup = $this->createMock( PageLookup::class );
			$pageLookup->method( 'getPageById' )
				->willReturn( $this->newPage() );
		}
		if ( !$revisionLookup ) {
			$revisionLookup = $this->createMock( RevisionLookup::class );
			$revisionLookup->method( 'getRevisionById' )->willReturn( $this->newRevision() );
		}

		return new RevisionHtmlFeatureCountsChangeIngress(
			$eventBusFactory,
			$streamNameMapper,
			$eventSerializer,
			$pageEntitySerializer,
			new RevisionEntitySerializer(),
			$pageLookup,
			$revisionLookup
		);
	}

	private function newNeverSendingEventBus(): EventBus {
		$eventBus = $this->createMock( EventBus::class );
		$eventBus->expects( $this->never() )->method( 'send' );
		return $eventBus;
	}

	public function testSendsOneEventToTheMappedStream(): void {
		$sent = [];
		$eventBus = $this->createMock( EventBus::class );
		$eventBus->expects( $this->once() )
			->method( 'send' )
			->willReturnCallback( static function ( array $events ) use ( &$sent ) {
				$sent = $events;
				return true;
			} );

		$this->newIngress( $eventBus )->handleRevisionLinkedArtifactPrefetchedEvent( self::newEvent() );

		$this->assertCount( 1, $sent );
		$this->assertSame( 'enwiki', $sent[0]['wiki_id'] );
		$this->assertSame( [ 'cause' => 'edit', 'is_reconciliation_request' => false ], $sent[0]['provenance'] );
		// The ingress passes the rendering fields to the serializer.
		$rendering = $sent[0]['revision']['rendering'];
		unset( $rendering['features']['compute_dt'] );
		$this->assertSame( self::BODY['revision']['rendering'], $rendering );
		// The revision fields come from the database, not from the artifact.
		$this->assertSame( '2026-08-09T03:40:11Z', $sent[0]['revision']['rev_dt'] );
		// dt is when MediaWiki recorded the state. compute_dt is when LAC computed the counts.
		$this->assertSame( '2026-09-30T13:00:00Z', $sent[0]['dt'] );
		$this->assertSame( '2026-09-30T12:00:05Z', $sent[0]['revision']['rendering']['features']['compute_dt'] );
	}

	public function testSendsWithoutUnknownFeatureCounts(): void {
		$sent = [];
		$eventBus = $this->createMock( EventBus::class );
		$eventBus->expects( $this->once() )
			->method( 'send' )
			->willReturnCallback( static function ( array $events ) use ( &$sent ) {
				$sent = $events;
				return true;
			} );

		$body = self::BODY;
		$body['revision']['rendering']['features']['counts']['message_box'] = 3;
		$this->newIngress( $eventBus )->handleRevisionLinkedArtifactPrefetchedEvent( self::newEvent( $body ) );

		$this->assertSame(
			[ 'reference' => 10, 'navbox' => 0 ],
			$sent[0]['revision']['rendering']['features']['counts']
		);
	}

	public function testIgnoresOtherArtifacts(): void {
		$pageLookup = $this->createMock( PageLookup::class );
		$pageLookup->expects( $this->never() )->method( 'getPageById' );

		$this->newIngress( $this->newNeverSendingEventBus(), $pageLookup )
			->handleRevisionLinkedArtifactPrefetchedEvent( self::newEvent( self::BODY, 'other_artifact' ) );
	}

	public function testSendsReconciliationRequestsWithTheProvenanceFlag(): void {
		$sent = [];
		$eventBus = $this->createMock( EventBus::class );
		$eventBus->expects( $this->once() )
			->method( 'send' )
			->willReturnCallback( static function ( array $events ) use ( &$sent ) {
				$sent = $events;
				return true;
			} );

		// E.g. a null edit.
		$event = self::newEvent( self::BODY, RevisionHtmlFeatureCountsChangeIngress::ARTIFACT_NAME, true );
		$this->newIngress( $eventBus )->handleRevisionLinkedArtifactPrefetchedEvent( $event );

		$this->assertSame( [ 'cause' => 'edit', 'is_reconciliation_request' => true ], $sent[0]['provenance'] );
	}

	/**
	 * @dataProvider provideTriggersThatAreNotAPageChange
	 */
	public function testSkipsPrefetchesThatAPageChangeDidNotTrigger( ?string $triggerEventType ): void {
		$event = self::newEvent(
			self::BODY, RevisionHtmlFeatureCountsChangeIngress::ARTIFACT_NAME, false, $triggerEventType
		);
		$this->newIngress( $this->newNeverSendingEventBus() )->handleRevisionLinkedArtifactPrefetchedEvent( $event );
	}

	public static function provideTriggersThatAreNotAPageChange(): iterable {
		yield 'no trigger' => [ null ];
		// E.g. a FlaggedRevs reader request for an older, published revision.
		yield 'another trigger' => [ 'FlaggedRevsReaderRequest' ];
	}

	public static function provideIncompleteSoftwareInfo(): iterable {
		$counts = self::COUNTS;

		yield 'no software_info' => [
			self::newBody( [ 'counts' => $counts ] ),
			null,
		];
		yield 'no software version' => [
			self::newBody( [ 'software_info' => [ 'name' => 'mwparserfromhtml' ], 'counts' => $counts ] ),
			[ 'name' => 'mwparserfromhtml' ],
		];
	}

	/**
	 * @dataProvider provideIncompleteSoftwareInfo
	 */
	public function testSendsWithIncompleteSoftwareInfo( array $body, ?array $expectedSoftwareInfo ): void {
		$sent = [];
		$eventBus = $this->createMock( EventBus::class );
		$eventBus->expects( $this->once() )
			->method( 'send' )
			->willReturnCallback( static function ( array $events ) use ( &$sent ) {
				$sent = $events;
				return true;
			} );

		$this->newIngress( $eventBus )->handleRevisionLinkedArtifactPrefetchedEvent( self::newEvent( $body ) );

		$rendering = $sent[0]['revision']['rendering'];
		$this->assertSame( $expectedSoftwareInfo, $rendering['features']['software_info'] ?? null );
		// The body has no render ID, render time or content details.
		$this->assertSame( [ 'features' ], array_keys( $rendering ) );
	}

	public function testThrowsForAValueWithTheWrongType(): void {
		$body = self::newBody( [ 'software_info' => [ 'name' => 1 ], 'counts' => self::COUNTS ] );

		$this->expectException( TypeError::class );
		$this->newIngress( $this->newNeverSendingEventBus() )
			->handleRevisionLinkedArtifactPrefetchedEvent( self::newEvent( $body ) );
	}

	public static function provideBodiesWithoutKnownCounts(): iterable {
		// The lambda found no features.
		yield 'empty counts' => [ '{"revision":{"rendering":{"features":{"counts":{}}}}}' ];
		yield 'only unknown counts' => [ '{"revision":{"rendering":{"features":{"counts":{"nav_box":1}}}}}' ];
	}

	/**
	 * @dataProvider provideBodiesWithoutKnownCounts
	 */
	public function testSendsWithoutKnownCountsAsAnEmptyObject( string $body ): void {
		$sent = [];
		$eventBus = $this->createMock( EventBus::class );
		$eventBus->expects( $this->once() )
			->method( 'send' )
			->willReturnCallback( static function ( array $events ) use ( &$sent ) {
				$sent = $events;
				return true;
			} );

		$this->newIngress( $eventBus )->handleRevisionLinkedArtifactPrefetchedEvent( self::newEvent( $body ) );

		$this->assertSame( '{}', json_encode( $sent[0]['revision']['rendering']['features']['counts'] ) );
	}

	public static function provideBodiesThatBreakTheContract(): iterable {
		$softwareInfo = self::SOFTWARE_INFO;

		yield 'not JSON' => [ 'not json' ];
		yield 'a JSON list' => [ '[1,2]' ];
		yield 'no revision.rendering' => [ [ 'revision' => [ 'rev_id' => self::REVISION_ID ] ] ];
		yield 'no feature counts' => [ self::newBody( [ 'software_info' => $softwareInfo ] ) ];
		yield 'feature counts are a list' => [ self::newBody( [ 'counts' => [ 1, 2 ] ] ) ];
	}

	/**
	 * @dataProvider provideBodiesThatBreakTheContract
	 */
	public function testThrowsForABodyThatBreaksTheContract( array|string $body ): void {
		$this->expectException( UnexpectedValueException::class );

		$this->newIngress( $this->newNeverSendingEventBus() )
			->handleRevisionLinkedArtifactPrefetchedEvent( self::newEvent( $body ) );
	}

	public function testSkipsAMissingPage(): void {
		$pageLookup = $this->createMock( PageLookup::class );
		$pageLookup->expects( $this->once() )->method( 'getPageById' )->willReturn( null );

		$this->newIngress( $this->newNeverSendingEventBus(), $pageLookup )
			->handleRevisionLinkedArtifactPrefetchedEvent( self::newEvent() );
	}

	public function testSkipsAMissingRevision(): void {
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionLookup->method( 'getRevisionById' )->willReturn( null );

		$this->newIngress( $this->newNeverSendingEventBus(), null, $revisionLookup )
			->handleRevisionLinkedArtifactPrefetchedEvent( self::newEvent() );
	}

	public static function provideRevisionsThatChangedSinceTheComputation(): iterable {
		yield 'merged into another page' => [ self::PAGE_ID + 1, 0 ];
		yield 'content hidden' => [ self::PAGE_ID, RevisionRecord::DELETED_TEXT ];
	}

	/**
	 * @dataProvider provideRevisionsThatChangedSinceTheComputation
	 */
	public function testSendsForARevisionThatChangedSinceTheComputation( int $pageId, int $visibility ): void {
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionLookup->expects( $this->once() )
			->method( 'getRevisionById' )
			->with( self::REVISION_ID )
			->willReturn( $this->newRevision( $pageId, $visibility ) );

		$eventBus = $this->createMock( EventBus::class );
		$eventBus->expects( $this->once() )->method( 'send' );

		$this->newIngress( $eventBus, null, $revisionLookup )
			->handleRevisionLinkedArtifactPrefetchedEvent( self::newEvent() );
	}
}
