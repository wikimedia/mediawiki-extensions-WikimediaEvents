<?php

declare( strict_types=1 );

namespace WikimediaEvents\Tests\Integration\EventDataProducts;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Http\Telemetry;
use MediaWiki\Json\FormatJson;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageStoreRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\UUID\GlobalIdGenerator;
use WikimediaEvents\EventDataProducts\RevisionRenderingFeatureCountsChangeEventSerializer;

/**
 * @covers \WikimediaEvents\EventDataProducts\RevisionRenderingFeatureCountsChangeEventSerializer
 */
class RevisionRenderingFeatureCountsChangeEventSerializerTest extends MediaWikiIntegrationTestCase {
	use MockWikiMapTrait;

	private const STREAM = 'mediawiki.revision_html_feature_counts_change.v1';
	private const MOCK_UUID = '3f6c2a1e-8b4d-4c7e-9a2f-1d5e6b7c8a90';
	private const REQUEST_ID = '7a3e1f0c9b2d4e6f8a1b2c3d';

	/**
	 * The same event as examples[0] of the development/revision_rendering_feature_counts_change
	 * schema. If you change one, change the other.
	 */
	private const EXPECTED_EVENT = [
		'$schema' => '/development/revision_rendering_feature_counts_change/1.0.0',
		'meta' => [
			'stream' => self::STREAM,
			'uri' => 'https://en.wikipedia.org/wiki/Christie_Cleek',
			'request_id' => self::REQUEST_ID,
			'id' => self::MOCK_UUID,
			'domain' => 'en.wikipedia.org',
		],
		'dt' => '2026-09-30T12:00:05Z',
		'wiki_id' => 'enwiki',
		'page' => [
			'page_id' => 1576250,
			'page_title' => 'Christie_Cleek',
			'namespace_id' => 0,
			'is_redirect' => false,
			'namespace_is_content' => true,
		],
		'revision' => [
			'rev_id' => 1368453569,
			'rev_dt' => '2026-08-09T03:40:11Z',
			'rev_parent_id' => 1368286382,
			'rev_sha1' => '5vejfybry6yr7x0xfv02tn5s1ze3d4f',
			'rev_size' => 9876,
			'comment' => 'An edit summary',
			'is_minor_edit' => false,
			'is_comment_visible' => true,
			'is_content_visible' => true,
			'is_editor_visible' => true,
			'rendering' => [
				'render_id' => '83c65a97-bc3e-11f1-9680-b5b59ebb64f0',
				'render_dt' => '2026-09-29T19:02:50Z',
				'content' => [
					'content_length' => 43807,
					'content_type' => 'text/html; charset=utf-8; '
						. 'profile="https://www.mediawiki.org/wiki/Specs/HTML/2.8.0"',
					'content_language' => 'en',
					'content_uri' => 'https://en.wikipedia.org/w/rest.php/v1/revision/1368453569/html',
				],
				'features' => [
					'compute_dt' => '2026-09-30T12:00:03Z',
					'software_info' => [
						'name' => 'mwparserfromhtml',
						'version' => '2.1.2',
					],
					'counts' => [
						'reference' => 10,
						'citation' => 18,
						'external_link' => 8,
						'wikilink' => 30,
						'category' => 16,
						'section' => 7,
						'heading' => 6,
						'template' => 16,
						'text_format' => 43,
						'comment' => 0,
						'list' => 3,
						'math' => 0,
						'infobox' => 0,
						'wikitable' => 0,
						'navbox' => 0,
						'messagebox' => 0,
						'inline_cleanup' => 0,
						'notes' => 0,
						'media_img' => 0,
						'media_audio' => 0,
						'media_video' => 0,
					],
				],
			],
		],
		'provenance' => [
			'cause' => 'edit',
			'is_reconciliation_request' => false,
		],
	];

	private RevisionRenderingFeatureCountsChangeEventSerializer $serializer;

	protected function setUp(): void {
		parent::setUp();
		$this->mockWikiMap( 'https://en.wikipedia.org' );
		$this->overrideConfigValues( [
			MainConfigNames::CanonicalServer => 'https://en.wikipedia.org',
			MainConfigNames::ArticlePath => '/wiki/$1',
			MainConfigNames::ContentNamespaces => [ NS_MAIN ],
		] );
		Telemetry::getInstance()->overrideRequestId( self::REQUEST_ID );

		$globalIdGenerator = $this->createMock( GlobalIdGenerator::class );
		$globalIdGenerator->method( 'newUUIDv4' )->willReturn( self::MOCK_UUID );

		$services = $this->getServiceContainer();
		$this->serializer = new RevisionRenderingFeatureCountsChangeEventSerializer(
			new EventSerializer( $globalIdGenerator ),
			$services->get( 'EventBus.PageEntitySerializer' ),
			$services->get( 'EventBus.RevisionEntitySerializer' )
		);
	}

	protected function tearDown(): void {
		ConvertibleTimestamp::setFakeTime( false );
		Telemetry::getInstance()->regenerateRequestId();
		parent::tearDown();
	}

	private static function newPage(): PageStoreRecord {
		return new PageStoreRecord(
			(object)[
				'page_id' => 1576250,
				'page_namespace' => NS_MAIN,
				'page_title' => 'Christie_Cleek',
				'page_is_redirect' => 0,
				'page_is_new' => 0,
				'page_latest' => 1368453569,
				'page_touched' => '20260809034011',
			],
			PageStoreRecord::LOCAL
		);
	}

	private function newRevision(): RevisionRecord {
		// A stub, because a real RevisionRecord computes its sha1 from its content.
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getId' )->willReturn( 1368453569 );
		$revision->method( 'getTimestamp' )->willReturn( '20260809034011' );
		$revision->method( 'getParentId' )->willReturn( 1368286382 );
		$revision->method( 'getSha1' )->willReturn( '5vejfybry6yr7x0xfv02tn5s1ze3d4f' );
		$revision->method( 'getSize' )->willReturn( 9876 );
		$revision->method( 'isMinor' )->willReturn( false );
		$revision->method( 'getVisibility' )->willReturn( 0 );
		$revision->method( 'getComment' )
			->willReturn( CommentStoreComment::newUnsavedComment( 'An edit summary' ) );
		return $revision;
	}

	private function toEvent(
		?string $cause = 'edit',
		?bool $isReconciliationRequest = false,
		string $timestamp = '20260930120005',
		string $computeTimestamp = '20260930120003'
	): array {
		$page = self::newPage();
		$rendering = self::EXPECTED_EVENT['revision']['rendering'];
		return $this->serializer->toEvent(
			self::STREAM,
			$page,
			$this->newRevision(),
			new ConvertibleTimestamp( $timestamp ),
			new ConvertibleTimestamp( $computeTimestamp ),
			self::EXPECTED_EVENT['revision']['rendering']['features']['counts'],
			'mwparserfromhtml',
			'2.1.2',
			$rendering['render_id'],
			$rendering['render_dt'],
			$rendering['content']['content_length'],
			$rendering['content']['content_type'],
			$rendering['content']['content_language'],
			$rendering['content']['content_uri'],
			$cause,
			$isReconciliationRequest
		);
	}

	public function testToEventIsTheSchemaExample(): void {
		// The page is local, so wiki_id is the wiki ID of the test wiki.
		$expected = [ 'wiki_id' => WikiMap::getCurrentWikiId() ] + self::EXPECTED_EVENT;
		$this->assertEquals( $expected, $this->toEvent() );
	}

	public function testDtAndComputeDtAreTheGivenTimesNotTheSerializationTime(): void {
		ConvertibleTimestamp::setFakeTime( '20261001000000' );

		$event = $this->toEvent( 'edit', false, '20260930083000', '20260920083000' );
		$this->assertSame( '2026-09-30T08:30:00Z', $event['dt'] );
		// E.g. a restored revision: the counts were computed before MediaWiki recorded them.
		$this->assertSame( '2026-09-20T08:30:00Z', $event['revision']['rendering']['features']['compute_dt'] );
	}

	public function testToEventKeepsTimestampStringsAsTheyAre(): void {
		$event = $this->serializer->toEvent(
			self::STREAM,
			self::newPage(),
			$this->newRevision(),
			'2026-09-30T14:00:05.123+02:00',
			new ConvertibleTimestamp( '20260930120003' ),
			[ 'reference' => 1 ],
			null,
			null,
			null,
			'2026-09-29T19:02:50.5Z'
		);

		// Strings keep their precision and offset. ConvertibleTimestamps become UTC date-times.
		$this->assertSame( '2026-09-30T14:00:05.123+02:00', $event['dt'] );
		$this->assertSame( '2026-09-30T12:00:03Z', $event['revision']['rendering']['features']['compute_dt'] );
		$this->assertSame( '2026-09-29T19:02:50.5Z', $event['revision']['rendering']['render_dt'] );
	}

	public function testToEventWithOnlyCountsHasOnlyCounts(): void {
		// No compute time, software info, render ID, render time or content details.
		$event = $this->toEventWithCounts( [ 'reference' => 1 ] );
		$this->assertSame(
			[ 'features' => [ 'counts' => [ 'reference' => 1 ] ] ],
			$event['revision']['rendering']
		);
	}

	public function testToEventWithoutProvenanceValuesHasNoProvenance(): void {
		$this->assertArrayNotHasKey( 'provenance', $this->toEvent( null, null ) );
	}

	public function testToEventOmitsAnUnknownProvenanceValue(): void {
		// Unknown values are absent, not null.
		$this->assertSame( [ 'is_reconciliation_request' => true ], $this->toEvent( null, true )['provenance'] );
		$this->assertSame( [ 'cause' => 'edit' ], $this->toEvent( 'edit', null )['provenance'] );
	}

	private function toEventWithCounts( array $featureCounts ): array {
		$page = self::newPage();
		return $this->serializer->toEvent(
			self::STREAM,
			$page,
			$this->newRevision(),
			new ConvertibleTimestamp( '20260930120005' ),
			null,
			$featureCounts
		);
	}

	public function testToEventKeepsOnlyKnownNonNegativeIntegerCounts(): void {
		$event = $this->toEventWithCounts( [
			'reference' => 10,
			'navbox' => 0,
			// Unknown feature name.
			'nav_box' => 1,
			// Negative.
			'citation' => -1,
			// Not an integer.
			'wikilink' => '30',
			'section' => 1.5,
		] );
		$this->assertSame(
			[ 'reference' => 10, 'navbox' => 0 ],
			$event['revision']['rendering']['features']['counts']
		);
	}

	public function testToEventWithEmptyCountsHasAnEmptyObject(): void {
		$event = $this->toEventWithCounts( [] );
		// An empty PHP array would be encoded as a JSON list, which the schema does not allow.
		$this->assertSame( '{}', FormatJson::encode( $event['revision']['rendering']['features']['counts'] ) );
	}

	public function testToEventWithoutKnownCountsHasAnEmptyObject(): void {
		$event = $this->toEventWithCounts( [ 'nav_box' => 1 ] );
		$this->assertSame( '{}', FormatJson::encode( $event['revision']['rendering']['features']['counts'] ) );
	}
}
