<?php

declare( strict_types=1 );

namespace WikimediaEvents\Tests\Unit\EventDataProducts;

use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\Extension\Thanks\UserThankedEvent;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikimediaEvents\EventDataProducts\UserThankEventIngress;

/**
 * @covers \WikimediaEvents\EventDataProducts\UserThankEventIngress
 */
class UserThankEventIngressTest extends MediaWikiUnitTestCase {

	private const MAPPED_STREAM = 'mediawiki.user_thank.rc0';
	private const THANKS_LOG_URL = 'https://en.wikipedia.org/wiki/Special:Log/thanks?user=Sender';

	protected function setUp(): void {
		parent::setUp();
		// UserThankedEvent is defined by the Thanks extension.
		if ( !class_exists( UserThankedEvent::class ) ) {
			$this->markTestSkipped( 'Thanks extension is not available' );
		}
		// WikiMap::getCurrentWikiId() reads these globals.
		$GLOBALS['wgDBname'] = 'enwiki';
		$GLOBALS['wgDBprefix'] = '';
		$GLOBALS['wgDBmwschema'] = null;
	}

	private static function newEvent(): UserThankedEvent {
		return new UserThankedEvent(
			new UserIdentityValue( 1, 'Sender' ),
			new UserIdentityValue( 2, 'Recipient' ),
			new ConvertibleTimestamp( '20260101000000' )
		);
	}

	private function newIngress( EventBus $eventBus ): UserThankEventIngress {
		$eventBusFactory = $this->createMock( EventBusFactory::class );
		$eventBusFactory->method( 'getInstanceForStream' )
			->with( self::MAPPED_STREAM )
			->willReturn( $eventBus );

		$streamNameMapper = $this->createMock( StreamNameMapper::class );
		$streamNameMapper->method( 'resolve' )
			->with( UserThankEventIngress::STREAM_NAME_DEFAULT )
			->willReturn( self::MAPPED_STREAM );

		// Return the event attributes plus meta.stream and meta.uri, so that WikiMap is not used.
		$eventSerializer = $this->createMock( EventSerializer::class );
		$eventSerializer->method( 'createEvent' )->willReturnCallback(
			static fn ( $schema, $stream, $uri, array $eventAttrs ): array =>
				$eventAttrs + [ 'meta' => [ 'stream' => $stream, 'uri' => $uri ] ]
		);

		// A full user entity, so that the test can check that both users keep all fields.
		$userEntitySerializer = $this->createMock( UserEntitySerializer::class );
		$userEntitySerializer->method( 'toArray' )->willReturnCallback(
			static fn ( UserIdentity $user ): array => [
				'user_id' => $user->getId(),
				'user_text' => $user->getName(),
				'groups' => [ '*', 'user' ],
				'edit_count' => 10,
				'is_temp' => false,
			]
		);

		$title = $this->createMock( Title::class );
		$title->method( 'getCanonicalURL' )->willReturn( self::THANKS_LOG_URL );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitle' )->with( NS_SPECIAL, 'Log/thanks' )->willReturn( $title );

		return new UserThankEventIngress(
			$eventBusFactory,
			$streamNameMapper,
			$eventSerializer,
			$userEntitySerializer,
			$titleFactory
		);
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

		$this->newIngress( $eventBus )->handleUserThankedEvent( self::newEvent() );

		$this->assertCount( 1, $sent );
		$event = $sent[0];
		$this->assertSame( self::MAPPED_STREAM, $event['meta']['stream'] );
		$this->assertSame( self::THANKS_LOG_URL, $event['meta']['uri'] );
		$this->assertSame( '2026-01-01T00:00:00Z', $event['dt'] );
		$this->assertSame( 'Sender', $event['performer']['user_text'] );
		$this->assertSame( 'Recipient', $event['recipient']['user_text'] );
	}

	public function testPerformerAndRecipientAreFullEntities(): void {
		$sent = [];
		$eventBus = $this->createMock( EventBus::class );
		$eventBus->method( 'send' )->willReturnCallback( static function ( array $events ) use ( &$sent ) {
			$sent = $events;
			return true;
		} );

		$this->newIngress( $eventBus )->handleUserThankedEvent( self::newEvent() );

		$event = $sent[0];
		$this->assertArrayHasKey( 'groups', $event['performer'] );
		$this->assertArrayHasKey( 'edit_count', $event['performer'] );
		$this->assertSame(
			[
				'user_id' => 2,
				'user_text' => 'Recipient',
				'groups' => [ '*', 'user' ],
				'edit_count' => 10,
				'is_temp' => false,
			],
			$event['recipient']
		);
	}
}
