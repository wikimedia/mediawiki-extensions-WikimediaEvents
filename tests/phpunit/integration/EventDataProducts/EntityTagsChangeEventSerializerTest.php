<?php

declare( strict_types=1 );

namespace WikimediaEvents\Tests\Integration\EventDataProducts;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Http\Telemetry;
use MediaWiki\Logging\LogRecord;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\UUID\GlobalIdGenerator;
use WikimediaEvents\EventDataProducts\EntityTagsChangeEventSerializer;

/**
 * @covers \WikimediaEvents\EventDataProducts\EntityTagsChangeEventSerializer
 * @group Database
 */
class EntityTagsChangeEventSerializerTest extends MediaWikiIntegrationTestCase {
	use MockWikiMapTrait;

	private const MOCK_UUID = '00000000-0000-4000-8000-000000000000';
	private const MOCK_REVISION_STREAM_NAME = 'mediawiki.revision_tags_change.v1';
	private const MOCK_LOG_STREAM_NAME = 'mediawiki.log_tags_change.v1';
	private const MOCK_TIMESTAMP = '20250101000000';
	/** MOCK_TIMESTAMP as an ISO 8601 date-time. This is the expected event dt. */
	private const MOCK_DT = '2025-01-01T00:00:00Z';
	private const MOCK_LOG_ID = 123;

	private PageEntitySerializer $pageEntitySerializer;
	private RevisionEntitySerializer $revisionEntitySerializer;
	private UserEntitySerializer $userEntitySerializer;
	private EntityTagsChangeEventSerializer $serializer;

	protected function setUp(): void {
		parent::setUp();

		$this->mockWikiMap();

		$globalIdGenerator = $this->createMock( GlobalIdGenerator::class );
		$globalIdGenerator->method( 'newUUIDv4' )->willReturn( self::MOCK_UUID );

		$services = $this->getServiceContainer();
		// Use the EventBus service instances of the entity serializers.
		$this->pageEntitySerializer = $services->get( 'EventBus.PageEntitySerializer' );
		$this->revisionEntitySerializer = $services->get( 'EventBus.RevisionEntitySerializer' );
		$this->userEntitySerializer = $services->get( 'EventBus.UserEntitySerializer' );

		$this->serializer = new EntityTagsChangeEventSerializer(
			new EventSerializer( $globalIdGenerator ),
			$this->pageEntitySerializer,
			$this->revisionEntitySerializer,
			$this->userEntitySerializer,
			$services->getTitleFactory(),
			$services->getChangeTagsStore()
		);
	}

	/**
	 * Makes the tag data for one tag, in the format of EntityTagsUpdatedEvent.
	 */
	private static function tagData( int $id, ?string $params = null ): array {
		return [ 'id' => $id, 'params' => $params ];
	}

	/**
	 * Makes a log entry to tag. The log entry is not in the database.
	 */
	private function newLogRecord(): LogRecord {
		return new LogRecord(
			self::MOCK_LOG_ID,
			'delete',
			'revision',
			new UserIdentityValue( 7, 'LogPerformer' ),
			new PageReferenceValue( NS_MAIN, 'Test', WikiAwareEntity::LOCAL ),
			self::MOCK_TIMESTAMP
		);
	}

	/**
	 * Makes a log tags change event with no performer.
	 */
	private function makeLogTagsChangeEvent( array $tags, array $priorTags ): array {
		return $this->serializer->toLogTagsChangeEvent(
			self::MOCK_LOG_STREAM_NAME,
			new ConvertibleTimestamp( self::MOCK_TIMESTAMP ),
			$this->newLogRecord(),
			$tags,
			$priorTags,
			null
		);
	}

	public function testToRevisionTagsChangeEvent(): void {
		$wikiPage = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'TaggedPage' )
		);
		$revisionRecord = $wikiPage->getRevisionRecord();

		$event = $this->serializer->toRevisionTagsChangeEvent(
			self::MOCK_REVISION_STREAM_NAME,
			new ConvertibleTimestamp( self::MOCK_TIMESTAMP ),
			$wikiPage,
			$revisionRecord,
			[ 'mw-reverted' => self::tagData( 1 ), 'mw-rollback' => self::tagData( 2 ) ],
			[ 'mw-rollback' => self::tagData( 2 ) ],
			null
		);

		$this->assertSame( EntityTagsChangeEventSerializer::REVISION_TAGS_CHANGE_SCHEMA_URI, $event['$schema'] );
		$this->assertSame( self::MOCK_REVISION_STREAM_NAME, $event['meta']['stream'] );
		$this->assertSame( self::MOCK_UUID, $event['meta']['id'] );
		$this->assertStringEndsWith(
			'Special:Redirect/revision/' . $revisionRecord->getId(),
			$event['meta']['uri']
		);
		$this->assertSame( Telemetry::getInstance()->getRequestId(), $event['meta']['request_id'] );
		$this->assertArrayHasKey( 'domain', $event['meta'] );
		$this->assertSame( self::MOCK_DT, $event['dt'] );
		$this->assertSame( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
		$this->assertSame(
			$this->pageEntitySerializer->toArray(
				$wikiPage,
				EntityTagsChangeEventSerializer::PAGE_ENTITY_SCHEMA_VERSION
			),
			$event['page']
		);
		$this->assertSame(
			$this->revisionEntitySerializer->toArray(
				$revisionRecord,
				EntityTagsChangeEventSerializer::REVISION_ENTITY_SCHEMA_VERSION
			),
			$event['revision']
		);
		$this->assertSame(
			[
				[ 'tag_name' => 'mw-reverted', 'tag_id' => 1 ],
				[ 'tag_name' => 'mw-rollback', 'tag_id' => 2 ],
			],
			$event['tags']
		);
		$this->assertSame(
			[ [ 'tag_name' => 'mw-rollback', 'tag_id' => 2 ] ],
			$event['prior_state']['tags']
		);
		$this->assertArrayNotHasKey( 'performer', $event );
		$this->assertArrayNotHasKey( 'log', $event );
	}

	public function testToRevisionTagsChangeEventWithPerformer(): void {
		$wikiPage = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'TaggedPage' )
		);
		$performer = $this->getTestSysop()->getUser();

		$event = $this->serializer->toRevisionTagsChangeEvent(
			self::MOCK_REVISION_STREAM_NAME,
			new ConvertibleTimestamp( self::MOCK_TIMESTAMP ),
			$wikiPage,
			$wikiPage->getRevisionRecord(),
			[ 'mw-reverted' => self::tagData( 1 ) ],
			[],
			$performer
		);

		$this->assertSame(
			$this->userEntitySerializer->toArray(
				$performer,
				EntityTagsChangeEventSerializer::USER_ENTITY_SCHEMA_VERSION
			),
			$event['performer']
		);
	}

	public function testToLogTagsChangeEvent(): void {
		$event = $this->makeLogTagsChangeEvent(
			[ 'mw-new-redirect' => self::tagData( 3 ) ],
			[]
		);

		$this->assertSame( EntityTagsChangeEventSerializer::LOG_TAGS_CHANGE_SCHEMA_URI, $event['$schema'] );
		$this->assertSame( self::MOCK_LOG_STREAM_NAME, $event['meta']['stream'] );
		$this->assertSame( self::MOCK_UUID, $event['meta']['id'] );
		$this->assertStringEndsWith(
			'Special:Redirect/logid/' . self::MOCK_LOG_ID,
			$event['meta']['uri']
		);
		$this->assertSame( Telemetry::getInstance()->getRequestId(), $event['meta']['request_id'] );
		$this->assertArrayHasKey( 'domain', $event['meta'] );
		$this->assertSame( self::MOCK_DT, $event['dt'] );
		$this->assertSame( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
		$this->assertSame( [ 'log_id' => self::MOCK_LOG_ID ], $event['log'] );
		$this->assertSame(
			[ [ 'tag_name' => 'mw-new-redirect', 'tag_id' => 3 ] ],
			$event['tags']
		);
		$this->assertSame( [], $event['prior_state']['tags'] );
		$this->assertArrayNotHasKey( 'performer', $event );
		$this->assertArrayNotHasKey( 'page', $event );
		$this->assertArrayNotHasKey( 'revision', $event );
	}

	public function testToLogTagsChangeEventWithPerformer(): void {
		$performer = $this->getTestSysop()->getUser();

		$event = $this->serializer->toLogTagsChangeEvent(
			self::MOCK_LOG_STREAM_NAME,
			new ConvertibleTimestamp( self::MOCK_TIMESTAMP ),
			$this->newLogRecord(),
			[ 'mw-new-redirect' => self::tagData( 3 ) ],
			[],
			$performer
		);

		$this->assertSame(
			$this->userEntitySerializer->toArray(
				$performer,
				EntityTagsChangeEventSerializer::USER_ENTITY_SCHEMA_VERSION
			),
			$event['performer']
		);
	}

	/**
	 * If there are no tags, tags and prior_state.tags are empty lists.
	 */
	public function testEmptyTags(): void {
		$event = $this->makeLogTagsChangeEvent( [], [] );

		$this->assertSame( [], $event['tags'] );
		$this->assertSame( [ 'tags' => [] ], $event['prior_state'] );
	}

	/**
	 * A tag with params has the params field. A tag without params does not.
	 */
	public function testTagParams(): void {
		$event = $this->makeLogTagsChangeEvent(
			[ 'with-params' => self::tagData( 1, 'some params' ), 'without-params' => self::tagData( 2 ) ],
			[ 'with-params' => self::tagData( 1, 'old params' ) ]
		);

		$this->assertSame(
			[
				[ 'tag_name' => 'with-params', 'tag_id' => 1, 'params' => 'some params' ],
				[ 'tag_name' => 'without-params', 'tag_id' => 2 ],
			],
			$event['tags']
		);
		$this->assertSame(
			[ [ 'tag_name' => 'with-params', 'tag_id' => 1, 'params' => 'old params' ] ],
			$event['prior_state']['tags']
		);
	}

	public function testTagsAreInAlphabeticalOrder(): void {
		$event = $this->makeLogTagsChangeEvent(
			[ 'zeta' => self::tagData( 1 ), 'alpha' => self::tagData( 2 ), 'mu' => self::tagData( 3 ) ],
			[ 'mu' => self::tagData( 3 ), 'alpha' => self::tagData( 2 ) ]
		);

		$this->assertSame( [ 'alpha', 'mu', 'zeta' ], array_column( $event['tags'], 'tag_name' ) );
		$this->assertSame( [ 'alpha', 'mu' ], array_column( $event['prior_state']['tags'], 'tag_name' ) );
	}

	/**
	 * PHP changes the numeric string key '123' to the integer 123.
	 * The serializer must change the tag name back to a string.
	 */
	public function testNumericTagNameIsString(): void {
		$event = $this->makeLogTagsChangeEvent(
			[ '123' => self::tagData( 4 ) ],
			[ '123' => self::tagData( 4 ) ]
		);

		$this->assertSame( [ [ 'tag_name' => '123', 'tag_id' => 4 ] ], $event['tags'] );
		$this->assertSame( [ [ 'tag_name' => '123', 'tag_id' => 4 ] ], $event['prior_state']['tags'] );
	}

	/**
	 * Tags with the prefix mw-private- are private.
	 * See ChangeTagsStore::isRestrictedTag().
	 */
	public function testToRevisionTagsChangeEventRemovesPrivateTags(): void {
		$wikiPage = $this->getExistingTestPage(
			Title::makeTitle( $this->getDefaultWikitextNS(), 'TaggedPage' )
		);

		$event = $this->serializer->toRevisionTagsChangeEvent(
			self::MOCK_REVISION_STREAM_NAME,
			new ConvertibleTimestamp( self::MOCK_TIMESTAMP ),
			$wikiPage,
			$wikiPage->getRevisionRecord(),
			[
				'mw-private-added' => self::tagData( 1, 'private params' ),
				'mw-reverted' => self::tagData( 2 ),
			],
			[
				'mw-private-removed' => self::tagData( 3 ),
				'mw-rollback' => self::tagData( 4 ),
			],
			null
		);

		$this->assertSame( [ [ 'tag_name' => 'mw-reverted', 'tag_id' => 2 ] ], $event['tags'] );
		$this->assertSame( [ [ 'tag_name' => 'mw-rollback', 'tag_id' => 4 ] ], $event['prior_state']['tags'] );
	}

	public function testToLogTagsChangeEventRemovesPrivateTags(): void {
		$event = $this->makeLogTagsChangeEvent(
			[
				'mw-private-added' => self::tagData( 1, 'private params' ),
				'mw-new-redirect' => self::tagData( 2 ),
			],
			[
				'mw-private-removed' => self::tagData( 3 ),
			]
		);

		$this->assertSame( [ [ 'tag_name' => 'mw-new-redirect', 'tag_id' => 2 ] ], $event['tags'] );
		$this->assertSame( [], $event['prior_state']['tags'] );
	}

	/**
	 * If only private tags change, tags and prior_state.tags are the same.
	 * The event does not show that a private tag changed.
	 */
	public function testOnlyPrivateTagsChanged(): void {
		$event = $this->makeLogTagsChangeEvent(
			[ 'mw-private-added' => self::tagData( 1 ), 'mw-rollback' => self::tagData( 2 ) ],
			[ 'mw-private-removed' => self::tagData( 3 ), 'mw-rollback' => self::tagData( 2 ) ]
		);

		$this->assertSame( [ [ 'tag_name' => 'mw-rollback', 'tag_id' => 2 ] ], $event['tags'] );
		$this->assertSame( $event['tags'], $event['prior_state']['tags'] );
	}

	/**
	 * If all tags are private, tags and prior_state.tags are empty lists.
	 */
	public function testAllTagsPrivate(): void {
		$event = $this->makeLogTagsChangeEvent(
			[ 'mw-private-added' => self::tagData( 1 ) ],
			[ 'mw-private-removed' => self::tagData( 2 ) ]
		);

		$this->assertSame( [], $event['tags'] );
		$this->assertSame( [], $event['prior_state']['tags'] );
	}

}
