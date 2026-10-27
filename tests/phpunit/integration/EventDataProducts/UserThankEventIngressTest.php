<?php

declare( strict_types=1 );

namespace WikimediaEvents\Tests\Integration\EventDataProducts;

use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\Thanks\UserThankedEvent;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikimediaEvents\EventDataProducts\UserThankEventIngress;
use WikimediaEvents\EventDataProducts\UserThankEventSerializer;

/**
 * Dispatches a UserThankedEvent and checks that UserThankEventIngress sends it.
 *
 * @covers \WikimediaEvents\EventDataProducts\UserThankEventIngress
 * @group Database
 */
class UserThankEventIngressTest extends MediaWikiIntegrationTestCase {
	use MockWikiMapTrait;

	/** @var array[] Events sent to the user_thank stream. */
	private array $sentEvents = [];

	protected function setUp(): void {
		parent::setUp();
		// UserThankedEvent is defined by the Thanks extension.
		$this->markTestSkippedIfExtensionNotLoaded( 'Thanks' );
		$this->mockWikiMap();

		// Send only the user_thank stream to the spy, and ignore other streams,
		// e.g. mediawiki.user_change from creating the test users.
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
				$stream === UserThankEventIngress::STREAM_NAME_DEFAULT
					? $spyEventBus
					: $otherEventBus
		);
		$this->setService( 'EventBus.EventBusFactory', $eventBusFactory );
	}

	public function testProducesAnEventForAThank(): void {
		$performer = $this->getTestUser()->getUser();
		$recipient = $this->getTestSysop()->getUser();
		// Flush events from creating the test users.
		$this->runDeferredUpdates();
		$this->sentEvents = [];

		$services = $this->getServiceContainer();
		$services->getDomainEventDispatcher()->dispatch(
			new UserThankedEvent( $performer, $recipient, new ConvertibleTimestamp() ),
			$services->getConnectionProvider()
		);
		$this->runDeferredUpdates();

		$this->assertCount( 1, $this->sentEvents, 'Should send exactly one event per thank' );
		$event = $this->sentEvents[0];
		$this->assertSame( UserThankEventSerializer::SCHEMA_URI, $event['$schema'] );
		$this->assertSame( UserThankEventIngress::STREAM_NAME_DEFAULT, $event['meta']['stream'] );
		$this->assertSame( $performer->getName(), $event['performer']['user_text'] );
		$this->assertSame( $recipient->getName(), $event['recipient']['user_text'] );
	}
}
