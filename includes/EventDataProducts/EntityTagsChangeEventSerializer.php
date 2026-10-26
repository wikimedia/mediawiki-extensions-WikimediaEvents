<?php

declare( strict_types=1 );

namespace WikimediaEvents\EventDataProducts;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Http\Telemetry;
use MediaWiki\Logging\LogRecord;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Converts changes to the change tags of a MediaWiki entity into events.
 * This class makes development/revision_tags_change and
 * development/log_tags_change events.
 *
 * Each event shows one RevisionTagsUpdatedEvent or LogTagsUpdatedEvent
 * domain event from MediaWiki core.
 * See https://phabricator.wikimedia.org/T434143
 *
 * The events do not contain private tags.
 * See https://phabricator.wikimedia.org/T432948
 *
 * @unstable
 */
class EntityTagsChangeEventSerializer {
	/**
	 * All revision tags change events have this $schema URI.
	 */
	public const REVISION_TAGS_CHANGE_SCHEMA_URI = '/development/revision_tags_change/1.0.0';

	/**
	 * All log tags change events have this $schema URI.
	 */
	public const LOG_TAGS_CHANGE_SCHEMA_URI = '/development/log_tags_change/1.0.0';

	/**
	 * The schema version of the page entity in revision tags change events.
	 */
	public const PAGE_ENTITY_SCHEMA_VERSION = '2.2.0';

	/**
	 * The schema version of the revision entity in revision tags change events.
	 */
	public const REVISION_ENTITY_SCHEMA_VERSION = '2.0.0';

	/**
	 * The schema version of the user entity for the performer.
	 */
	public const USER_ENTITY_SCHEMA_VERSION = '1.3.0';

	public function __construct(
		private readonly EventSerializer $eventSerializer,
		private readonly PageEntitySerializer $pageEntitySerializer,
		private readonly RevisionEntitySerializer $revisionEntitySerializer,
		private readonly UserEntitySerializer $userEntitySerializer,
		private readonly TitleFactory $titleFactory,
		private readonly ChangeTagsStore $changeTagsStore,
	) {
	}

	/**
	 * Makes a development/revision_tags_change event.
	 *
	 * @param string $stream
	 * @param ConvertibleTimestamp $eventTimestamp The time of the tags change. This is the event dt.
	 * @param ProperPageIdentity $page The page of the tagged revision.
	 *   If this is a PageRecord, page.is_redirect is correct.
	 *   Otherwise, page.is_redirect is always false.
	 * @param RevisionRecord $revisionRecord The tagged revision
	 * @param array<string,array{id:int,params:?string}> $tags The tags after the change,
	 *   as a map of tag name to tag data. See EntityTagsUpdatedEvent.
	 * @param array<string,array{id:int,params:?string}> $priorTags The tags before the change,
	 *   in the same format as $tags
	 * @param UserIdentity|null $performer The user who changed the tags, if known
	 * @return array
	 */
	public function toRevisionTagsChangeEvent(
		string $stream,
		ConvertibleTimestamp $eventTimestamp,
		ProperPageIdentity $page,
		RevisionRecord $revisionRecord,
		array $tags,
		array $priorTags,
		?UserIdentity $performer,
	): array {
		$eventAttrs = $this->toCommonAttrs( $eventTimestamp, $tags, $priorTags, $performer );

		$eventAttrs['page'] = $this->pageEntitySerializer->toArray(
			$page,
			self::PAGE_ENTITY_SCHEMA_VERSION
		);
		$eventAttrs['revision'] = $this->revisionEntitySerializer->toArray(
			$revisionRecord,
			self::REVISION_ENTITY_SCHEMA_VERSION
		);

		return $this->toEvent(
			self::REVISION_TAGS_CHANGE_SCHEMA_URI,
			$stream,
			$this->canonicalSpecialRedirectURL( 'revision/' . $revisionRecord->getId() ),
			$eventAttrs
		);
	}

	/**
	 * Makes a development/log_tags_change event.
	 *
	 * @param string $stream
	 * @param ConvertibleTimestamp $eventTimestamp The time of the tags change. This is the event dt.
	 * @param LogRecord $logRecord The tagged log entry
	 * @param array<string,array{id:int,params:?string}> $tags The tags after the change,
	 *   as a map of tag name to tag data. See EntityTagsUpdatedEvent.
	 * @param array<string,array{id:int,params:?string}> $priorTags The tags before the change,
	 *   in the same format as $tags
	 * @param UserIdentity|null $performer The user who changed the tags, if known
	 * @return array
	 */
	public function toLogTagsChangeEvent(
		string $stream,
		ConvertibleTimestamp $eventTimestamp,
		LogRecord $logRecord,
		array $tags,
		array $priorTags,
		?UserIdentity $performer,
	): array {
		$eventAttrs = $this->toCommonAttrs( $eventTimestamp, $tags, $priorTags, $performer );

		$eventAttrs['log'] = [
			'log_id' => $logRecord->getId(),
		];

		return $this->toEvent(
			self::LOG_TAGS_CHANGE_SCHEMA_URI,
			$stream,
			$this->canonicalSpecialRedirectURL( 'logid/' . $logRecord->getId() ),
			$eventAttrs
		);
	}

	/**
	 * Uses EventSerializer to make the event for the given $eventAttrs.
	 *
	 * @param string $schema
	 * @param string $stream
	 * @param string $uri
	 * @param array $eventAttrs
	 * @return array
	 */
	private function toEvent(
		string $schema,
		string $stream,
		string $uri,
		array $eventAttrs,
	): array {
		return $this->eventSerializer->createEvent(
			$schema,
			$stream,
			$uri,
			$eventAttrs,
			WikiMap::getCurrentWikiId(),
			null,
			Telemetry::getInstance()->getRequestId(),
		);
	}

	/**
	 * Sets the event fields that are common to all entity tags change events.
	 * The events do not contain private tags.
	 *
	 * @param ConvertibleTimestamp $eventTimestamp
	 * @param array<string,array{id:int,params:?string}> $tags
	 * @param array<string,array{id:int,params:?string}> $priorTags
	 * @param UserIdentity|null $performer
	 * @return array
	 */
	private function toCommonAttrs(
		ConvertibleTimestamp $eventTimestamp,
		array $tags,
		array $priorTags,
		?UserIdentity $performer,
	): array {
		$eventAttrs = [
			// TODO: When EventSerializer::timestampToDt() accepts a ConvertibleTimestamp,
			// give $eventTimestamp to it directly.
			'dt' => EventSerializer::timestampToDt( $eventTimestamp->getTimestamp() ),
			'wiki_id' => WikiMap::getCurrentWikiId(),
			'tags' => self::toTagsAttrs( $this->removePrivateTags( $tags ) ),
			'prior_state' => [
				'tags' => self::toTagsAttrs( $this->removePrivateTags( $priorTags ) ),
			],
		];

		if ( $performer !== null ) {
			$eventAttrs['performer'] = $this->userEntitySerializer->toArray(
				$performer,
				self::USER_ENTITY_SCHEMA_VERSION
			);
		}

		return $eventAttrs;
	}

	/**
	 * Removes the private tags from a map of tag name to tag data.
	 *
	 * @param array<string,array{id:int,params:?string}> $tags
	 * @return array<string,array{id:int,params:?string}>
	 */
	private function removePrivateTags( array $tags ): array {
		return array_filter(
			$tags,
			// PHP changes numeric string keys to integers.
			// Change the tag name back to a string, because isRestrictedTag() accepts only a string.
			fn ( $tagName ) => !$this->changeTagsStore->isRestrictedTag( (string)$tagName ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Converts a map of tag name to tag data into a list of tag entities.
	 * The tags in the list are in alphabetical order by tag name.
	 *
	 * @param array<string,array{id:int,params:?string}> $tags
	 * @return array[]
	 */
	private static function toTagsAttrs( array $tags ): array {
		ksort( $tags, SORT_STRING );

		$tagsAttrs = [];
		foreach ( $tags as $tagName => $tagData ) {
			$tagAttrs = [
				// PHP changes numeric string keys to integers.
				// Change the tag name back to a string, because the schema type is string.
				'tag_name' => (string)$tagName,
				'tag_id' => $tagData['id'],
			];

			// The schema does not accept null for params.
			// If the tag has no params, do not set params.
			if ( $tagData['params'] !== null ) {
				$tagAttrs['params'] = $tagData['params'];
			}

			$tagsAttrs[] = $tagAttrs;
		}

		return $tagsAttrs;
	}

	/**
	 * Returns the canonical URL of a Special:Redirect subpage.
	 * Special:Redirect/revision/<id> goes to the revision.
	 * Special:Redirect/logid/<id> goes to the log entry.
	 * The URL uses only the ID. Thus, the URL stays correct after a page move.
	 *
	 * @param string $subpage For example, 'revision/123' or 'logid/123'
	 * @return string
	 */
	private function canonicalSpecialRedirectURL( string $subpage ): string {
		return $this->titleFactory->makeTitle( NS_SPECIAL, 'Redirect/' . $subpage )
			->getCanonicalURL();
	}
}
