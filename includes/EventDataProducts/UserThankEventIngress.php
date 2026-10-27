<?php

declare( strict_types=1 );

namespace WikimediaEvents\EventDataProducts;

use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\Extension\Thanks\UserThankedEvent;
use MediaWiki\Extension\Thanks\UserThankedListener;
use MediaWiki\Title\TitleFactory;

/**
 * Produces mediawiki.user_thank events via EventBus, one per thank sent with the Thanks extension.
 *
 * Only instantiated when a UserThanked event is dispatched, i.e. when Thanks is loaded.
 *
 * @unstable
 */
class UserThankEventIngress
	extends DomainEventIngress
	implements UserThankedListener
{
	public const STREAM_NAME_DEFAULT = 'mediawiki.user_thank.v1';

	private readonly string $streamName;
	private readonly UserThankEventSerializer $serializer;

	public function __construct(
		private readonly EventBusFactory $eventBusFactory,
		StreamNameMapper $streamNameMapper,
		EventSerializer $eventSerializer,
		UserEntitySerializer $userEntitySerializer,
		TitleFactory $titleFactory
	) {
		// Allows the stream name to be overridden via $wgEventBusStreamNamesMap.
		$this->streamName = $streamNameMapper->resolve( self::STREAM_NAME_DEFAULT );
		$this->serializer = new UserThankEventSerializer(
			$eventSerializer,
			$userEntitySerializer,
			$titleFactory
		);
	}

	public function handleUserThankedEvent( UserThankedEvent $event ): void {
		$eventAttrs = $this->serializer->toEvent( $this->streamName, $event );

		// DomainEvent listeners already run in a DeferredUpdate, after the
		// Thanks transaction commits, so there is no need to defer the send again.
		$this->eventBusFactory->getInstanceForStream( $this->streamName )->send( [ $eventAttrs ] );
	}
}
