<?php

declare( strict_types=1 );

namespace WikimediaEvents\EventDataProducts;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\ChangeTags\Event\EntityTagsUpdatedEvent;
use MediaWiki\ChangeTags\Event\LogTagsUpdatedEvent;
use MediaWiki\ChangeTags\Event\LogTagsUpdatedListener;
use MediaWiki\ChangeTags\Event\RevisionTagsUpdatedEvent;
use MediaWiki\ChangeTags\Event\RevisionTagsUpdatedListener;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\EventBusSendUpdate;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\PageLookup;
use MediaWiki\Title\TitleFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Produces mediawiki.revision_tags_change.v1 and mediawiki.log_tags_change.v1 events
 * via EventBus from the RevisionTagsUpdated and LogTagsUpdated domain events.
 *
 * Each emitted stream is a changelog of the change tags of one kind of entity.
 * Each event is the tags of one revision or one log entry before and after an update.
 *
 * The events do not contain private tags. If an update changes only
 * private tags, this class does not send an event.
 * See https://phabricator.wikimedia.org/T434143
 *
 * @unstable
 */
class EntityTagsChangeIngress
	extends DomainEventIngress
	implements RevisionTagsUpdatedListener, LogTagsUpdatedListener
{
	public const REVISION_TAGS_CHANGE_STREAM_NAME_DEFAULT = 'mediawiki.revision_tags_change.v1';
	public const LOG_TAGS_CHANGE_STREAM_NAME_DEFAULT = 'mediawiki.log_tags_change.v1';

	private readonly string $revisionTagsChangeStreamName;
	private readonly string $logTagsChangeStreamName;
	private readonly EntityTagsChangeEventSerializer $serializer;
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly EventBusFactory $eventBusFactory,
		StreamNameMapper $streamNameMapper,
		EventSerializer $eventSerializer,
		PageEntitySerializer $pageEntitySerializer,
		RevisionEntitySerializer $revisionEntitySerializer,
		UserEntitySerializer $userEntitySerializer,
		TitleFactory $titleFactory,
		ChangeTagsStore $changeTagsStore,
		private readonly PageLookup $pageLookup,
	) {
		$this->revisionTagsChangeStreamName = $streamNameMapper->resolve(
			self::REVISION_TAGS_CHANGE_STREAM_NAME_DEFAULT
		);
		$this->logTagsChangeStreamName = $streamNameMapper->resolve(
			self::LOG_TAGS_CHANGE_STREAM_NAME_DEFAULT
		);
		$this->logger = LoggerFactory::getInstance( 'WikimediaEvents' );
		$this->serializer = new EntityTagsChangeEventSerializer(
			$eventSerializer,
			$pageEntitySerializer,
			$revisionEntitySerializer,
			$userEntitySerializer,
			$titleFactory,
			$changeTagsStore,
		);
	}

	public function handleRevisionTagsUpdatedEvent( RevisionTagsUpdatedEvent $event ): void {
		$revisionRecord = $event->getRevisionRecord();
		$logContext = [
			'stream' => $this->revisionTagsChangeStreamName,
			'page_id' => $revisionRecord->getPageId(),
			'revision_id' => $event->getRevisionId(),
		];

		if ( !self::hasPublicTagsChange( $event ) ) {
			$this->logSkip( 'debug', 'only private tags changed', $logContext );
			return;
		}

		// Read the page from the primary database. A new page can be missing
		// from a replica because of replication lag.
		// Use a PageRecord, because only a PageRecord gives the correct page.is_redirect.
		$page = $this->pageLookup->getPageById(
			$revisionRecord->getPageId(),
			IDBAccessObject::READ_LATEST
		);
		if ( $page === null ) {
			// The page can be deleted after the tags update and before this handler runs.
			// Core does not send this event for archived revisions now. If it does
			// in the future, the page of an archived revision does not exist.
			$this->logSkip( 'info', 'page does not exist', $logContext );
			return;
		}

		$eventAttrs = $this->serializer->toRevisionTagsChangeEvent(
			$this->revisionTagsChangeStreamName,
			$event->getEventTimestamp(),
			$page,
			$revisionRecord,
			$event->getPublicTagsAfter(),
			$event->getPublicTagsBefore(),
			$event->getPerformer()
		);

		DeferredUpdates::addUpdate( EventBusSendUpdate::newForStream(
			$this->eventBusFactory,
			$this->revisionTagsChangeStreamName,
			[ $eventAttrs ]
		) );
	}

	public function handleLogTagsUpdatedEvent( LogTagsUpdatedEvent $event ): void {
		$logContext = [
			'stream' => $this->logTagsChangeStreamName,
			'log_id' => $event->getLogId(),
		];

		if ( !self::hasPublicTagsChange( $event ) ) {
			$this->logSkip( 'debug', 'only private tags changed', $logContext );
			return;
		}

		$eventAttrs = $this->serializer->toLogTagsChangeEvent(
			$this->logTagsChangeStreamName,
			$event->getEventTimestamp(),
			$event->getLogRecord(),
			$event->getPublicTagsAfter(),
			$event->getPublicTagsBefore(),
			$event->getPerformer()
		);

		DeferredUpdates::addUpdate( EventBusSendUpdate::newForStream(
			$this->eventBusFactory,
			$this->logTagsChangeStreamName,
			[ $eventAttrs ]
		) );
	}

	/**
	 * Returns true if the update added or removed one or more public tags.
	 *
	 * If the update changed only private tags, do not send an event.
	 * That event has the same tags before and after the update.
	 * This shows that a private tag changed.
	 */
	private static function hasPublicTagsChange( EntityTagsUpdatedEvent $event ): bool {
		return $event->getAddedPublicTags() || $event->getRemovedPublicTags();
	}

	/**
	 * Logs a message about an event that this class does not send.
	 */
	private function logSkip( string $level, string $reason, array $logContext ): void {
		$this->logger->log(
			$level,
			'Not producing {stream} event: {reason}',
			[ 'reason' => $reason ] + $logContext
		);
	}
}
