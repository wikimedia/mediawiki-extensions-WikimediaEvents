<?php

declare( strict_types=1 );

namespace WikimediaEvents\Tests\Integration\EventDataProducts;

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\Thanks\UserThankedEvent;
use MediaWiki\Http\Telemetry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\UUID\GlobalIdGenerator;
use WikimediaEvents\EventDataProducts\UserThankEventSerializer;

/**
 * @covers \WikimediaEvents\EventDataProducts\UserThankEventSerializer
 * @group Database
 */
class UserThankEventSerializerTest extends MediaWikiIntegrationTestCase {
	use MockWikiMapTrait;
	use TempUserTestTrait;

	private const MOCK_UUID = '00000000-0000-4000-8000-000000000000';
	private const STREAM = 'mediawiki.user_thank.v1';
	private const TIMESTAMP = '20260101000000';

	private UserThankEventSerializer $serializer;

	protected function setUp(): void {
		parent::setUp();
		// UserThankedEvent is defined by the Thanks extension.
		$this->markTestSkippedIfExtensionNotLoaded( 'Thanks' );
		$this->mockWikiMap();

		$globalIdGenerator = $this->createMock( GlobalIdGenerator::class );
		$globalIdGenerator->method( 'newUUIDv4' )->willReturn( self::MOCK_UUID );

		$services = $this->getServiceContainer();
		$this->serializer = new UserThankEventSerializer(
			new EventSerializer( $globalIdGenerator ),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$services->getTitleFactory()
		);
	}

	private static function newEvent( UserIdentity $performer, UserIdentity $recipient ): UserThankedEvent {
		return new UserThankedEvent( $performer, $recipient, new ConvertibleTimestamp( self::TIMESTAMP ) );
	}

	public function testToEvent(): void {
		$performer = $this->getTestUser()->getUser();
		$recipient = $this->getTestSysop()->getUser();

		$event = $this->serializer->toEvent( self::STREAM, self::newEvent( $performer, $recipient ) );

		$this->assertSame( UserThankEventSerializer::SCHEMA_URI, $event['$schema'] );
		$this->assertSame( self::STREAM, $event['meta']['stream'] );
		$this->assertSame( self::MOCK_UUID, $event['meta']['id'] );
		$this->assertSame( Telemetry::getInstance()->getRequestId(), $event['meta']['request_id'] );
		$this->assertArrayHasKey( 'domain', $event['meta'] );
		// EventGate sets meta.dt.
		$this->assertArrayNotHasKey( 'dt', $event['meta'] );
		$this->assertSame( EventSerializer::timestampToDt( self::TIMESTAMP ), $event['dt'] );
		$this->assertSame( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
		$this->assertSame( $performer->getName(), $event['performer']['user_text'] );
		$this->assertSame( $recipient->getName(), $event['recipient']['user_text'] );
	}

	public function testMetaUriIsThanksLogOfPerformer(): void {
		$performer = $this->getTestUser()->getUser();
		$recipient = $this->getTestSysop()->getUser();

		$event = $this->serializer->toEvent( self::STREAM, self::newEvent( $performer, $recipient ) );

		$expectedUri = Title::makeTitle( NS_SPECIAL, 'Log/thanks' )
			->getCanonicalURL( [ 'user' => $performer->getName() ] );
		$this->assertSame( $expectedUri, $event['meta']['uri'] );
	}

	public function testPerformerAndRecipientAreFullEntities(): void {
		$performer = $this->getTestUser()->getUser();
		$recipient = $this->getTestSysop()->getUser();

		$event = $this->serializer->toEvent( self::STREAM, self::newEvent( $performer, $recipient ) );

		// Performer keeps non-identity fields.
		$this->assertArrayHasKey( 'groups', $event['performer'] );
		$this->assertArrayHasKey( 'edit_count', $event['performer'] );

		// Recipient also keeps non-identity fields.
		$this->assertArrayHasKey( 'groups', $event['recipient'] );
		$this->assertArrayHasKey( 'edit_count', $event['recipient'] );
		$this->assertSame( $recipient->getId(), $event['recipient']['user_id'] );
	}

	/**
	 * Thanks involving temporary accounts are emitted, with is_temp set.
	 */
	public function testTempUsersAreEmitted(): void {
		$this->enableAutoCreateTempUser();
		$tempUser = $this->getServiceContainer()->getTempUserCreator()
			->create( null, new FauxRequest() )->getUser();
		$namedUser = $this->getTestUser()->getUser();

		$event = $this->serializer->toEvent( self::STREAM, self::newEvent( $tempUser, $tempUser ) );
		$this->assertTrue( $event['performer']['is_temp'] );
		$this->assertTrue( $event['recipient']['is_temp'] );

		$event = $this->serializer->toEvent( self::STREAM, self::newEvent( $tempUser, $namedUser ) );
		$this->assertFalse( $event['recipient']['is_temp'] );
	}
}
