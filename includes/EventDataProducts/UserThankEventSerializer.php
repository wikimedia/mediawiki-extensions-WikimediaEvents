<?php

declare( strict_types=1 );

namespace WikimediaEvents\EventDataProducts;

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\Thanks\UserThankedEvent;
use MediaWiki\Http\Telemetry;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;

/**
 * Serializes a Thanks UserThankedEvent into an event
 * that conforms to the mediawiki/user/thank schema.
 *
 * @unstable
 */
class UserThankEventSerializer {

	public const SCHEMA_URI = '/mediawiki/user/thank/1.0.0';

	/** fragment/mediawiki/state/entity/user schema version to serialize at */
	public const USER_ENTITY_SCHEMA_VERSION = '1.3.0';

	public function __construct(
		private readonly EventSerializer $eventSerializer,
		private readonly UserEntitySerializer $userEntitySerializer,
		private readonly TitleFactory $titleFactory,
	) {
	}

	/**
	 * @param string $stream The stream to produce the event to.
	 * @param UserThankedEvent $event
	 * @return array
	 */
	public function toEvent( string $stream, UserThankedEvent $event ): array {
		$performer = $event->getPerformer();
		$wikiId = WikiMap::getCurrentWikiId();

		$eventAttrs = [
			// dt is when the thank was sent.
			'dt' => EventSerializer::timestampToDt( $event->getEventTimestamp()->getTimestamp() ),
			'wiki_id' => $wikiId,
			// Full user entities for the sender and the recipient.
			'performer' => $this->userEntitySerializer->toArray( $performer, self::USER_ENTITY_SCHEMA_VERSION ),
			'recipient' => $this->userEntitySerializer->toArray(
				$event->getRecipient(),
				self::USER_ENTITY_SCHEMA_VERSION
			),
		];

		return $this->eventSerializer->createEvent(
			self::SCHEMA_URI,
			$stream,
			$this->thanksLogURL( $performer ),
			$eventAttrs,
			$wikiId,
			// Let EventGate set meta.dt.
			null,
			// Links the event to the MediaWiki request (and its logs) in which the thank was sent.
			Telemetry::getInstance()->getRequestId()
		);
	}

	/**
	 * The canonical URL of Special:Log/thanks filtered to the performer,
	 * e.g. "https://en.wikipedia.org/wiki/Special:Log/thanks?user=Foo".
	 *
	 * @param UserIdentity $performer
	 * @return string
	 */
	private function thanksLogURL( UserIdentity $performer ): string {
		return $this->titleFactory->makeTitle( NS_SPECIAL, 'Log/thanks' )
			->getCanonicalURL( [ 'user' => $performer->getName() ] );
	}
}
