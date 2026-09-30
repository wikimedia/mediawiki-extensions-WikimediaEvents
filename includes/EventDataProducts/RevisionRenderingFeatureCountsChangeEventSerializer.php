<?php

declare( strict_types=1 );

namespace WikimediaEvents\EventDataProducts;

use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Http\Telemetry;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\WikiMap\WikiMap;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Serializes a revision rendering feature counts artifact body into an event
 * that conforms to the development/revision_rendering_feature_counts_change schema.
 *
 * @unstable
 */
class RevisionRenderingFeatureCountsChangeEventSerializer {

	public const SCHEMA_URI = '/development/revision_rendering_feature_counts_change/1.0.0';

	/**
	 * Feature names that the schema declares in revision.rendering.features.counts.
	 * The schema has additionalProperties: false, so EventGate rejects an event with any other name.
	 * Whenever you change the version in SCHEMA_URI, you should ensure that these keys match
	 * the counts fields defined in the schema's revision.rendering.feature.counts object.
	 */
	public const COUNT_KEYS = [
		'section',
		'wikilink',
		'external_link',
		'category',
		'reference',
		'citation',
		'list',
		'infobox',
		'messagebox',
		'navbox',
		'template',
		'media_audio',
		'media_img',
		'media_video',
		'heading',
		'wikitable',
		'math',
		'comment',
		'inline_cleanup',
		'notes',
		'text_format',
	];

	/** fragment/mediawiki/state/entity/page schema version to serailize at */
	private const PAGE_ENTITY_SCHEMA_VERSION = '2.2.0';

		/** fragment/mediawiki/state/entity/revision schema version to serailize at */
	private const REVISION_ENTITY_SCHEMA_VERSION = '2.0.0';

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly EventSerializer $eventSerializer,
		private readonly PageEntitySerializer $pageEntitySerializer,
		private readonly RevisionEntitySerializer $revisionEntitySerializer,
		?LoggerInterface $logger = null
	) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Keep only the feature counts that the schema allows: a known feature name with a
	 * non-negative integer value.
	 *
	 * @param array $featureCounts The feature counts from the artifact.
	 * @return array{counts: array<string,int>, dropped: string[]} The counts to emit, and
	 *   the names of the counts that were dropped.
	 */
	private function filterKnownCounts( array $featureCounts ): array {
		$counts = [];
		$dropped = [];
		foreach ( $featureCounts as $name => $value ) {
			$name = (string)$name;
			if ( in_array( $name, self::COUNT_KEYS, true ) && is_int( $value ) && $value >= 0 ) {
				$counts[$name] = $value;
			} else {
				$dropped[] = $name;
			}
		}
		return [ 'counts' => $counts, 'dropped' => $dropped ];
	}

	/**
	 * Timestamp parameters accept a string or a ConvertibleTimestamp. A string must be an
	 * RFC 3339 date-time (the schema's format: date-time), and is not changed, so it keeps its
	 * precision. A ConvertibleTimestamp is formatted as a UTC date-time.
	 *
	 * @param string $stream
	 *   The stream to produce the event to.
	 * @param ExistingPageRecord $page
	 *   The page as of now.
	 * @param RevisionRecord $revision
	 * @param string|ConvertibleTimestamp $timestamp
	 *   When MediaWiki (pre)fetched the feature counts state.
	 *   This is the event dt.
	 * @param string|ConvertibleTimestamp|null $computeTimestamp When the feature counts were computed,
	 *   if known. This is revision.rendering.features.compute_dt.
	 *   This comes from the artifact's Last-Modified header.
	 * @param array $featureCounts
	 *   The feature counts from the artifact. They will be filtered to the
	 *   feature fields that the schema defines ({@link COUNT_KEYS}).
	 *   An absent feature means that it was not counted, so the counts can be empty.
	 * @param string|null $softwareName
	 *   The name of the software that computed the counts.
	 * @param string|null $softwareVersion
	 *   The version of the software that computed the counts.
	 * @param string|null $renderId
	 *   The render ID of the rendering the counts were computed from.
	 * @param string|ConvertibleTimestamp|null $renderTimestamp
	 *   When the revision's (HTML) rendering was made.
	 * @param int|null $contentLength
	 *   The size of the rendered content in bytes.
	 * @param string|null $contentType
	 *   The media type of the rendered content.
	 * @param string|null $contentLanguage
	 *   The language of the rendered content.
	 * @param string|null $contentUri
	 *   The URI of the rendered content.
	 * @param string|null $provenanceCause
	 *   provenance.cause: a description of what caused this change, if known.
	 *   Often this is one of the {{@link PageUpdateCauses}}.
	 * @param bool|null $provenanceIsReconciliationRequest
	 *   provenance.is_reconciliation_request:
	 *   whether the upstream change was a reconciliation request (e.g. a null edit), if known.
	 * @return array
	 */
	public function toEvent(
		string $stream,
		ExistingPageRecord $page,
		RevisionRecord $revision,
		string|ConvertibleTimestamp $timestamp,
		string|ConvertibleTimestamp|null $computeTimestamp,
		array $featureCounts,
		?string $softwareName = null,
		?string $softwareVersion = null,
		?string $renderId = null,
		string|ConvertibleTimestamp|null $renderTimestamp = null,
		?int $contentLength = null,
		?string $contentType = null,
		?string $contentLanguage = null,
		?string $contentUri = null,
		?string $provenanceCause = null,
		?bool $provenanceIsReconciliationRequest = null,
	): array {
		$wikiId = self::getWikiId( $page );

		[ 'counts' => $featureCounts, 'dropped' => $dropped ] = $this->filterKnownCounts( $featureCounts );
		$logContext = [ 'wiki_id' => $wikiId, 'revision_id' => $revision->getId() ];
		if ( $dropped ) {
			// TODO: we should add metrics for this and other things.
			$this->logger->warning(
				'Dropped unknown or invalid feature counts: {features}',
				[ 'features' => implode( ', ', $dropped ) ] + $logContext
			);
		}

		$rendering = self::withoutNullValues( [
			'render_id' => $renderId,
			'render_dt' => self::toDt( $renderTimestamp ),
		] );
		$content = self::withoutNullValues( [
			'content_length' => $contentLength,
			'content_type' => $contentType,
			'content_language' => $contentLanguage,
			'content_uri' => $contentUri,
		] );

		if ( $content ) {
			$rendering['content'] = $content;
		}

		$features = self::withoutNullValues( [
			'compute_dt' => self::toDt( $computeTimestamp ),
		] );

		$softwareInfo = self::withoutNullValues( [
			'name' => $softwareName,
			'version' => $softwareVersion,
		] );

		// An empty PHP array is encoded as a JSON list, which the schema does not allow.
		if ( $softwareInfo ) {
			$features['software_info'] = $softwareInfo;
		}
		// An empty PHP array is encoded as a JSON list. The schema needs an object.
		$features['counts'] = $featureCounts ?: (object)[];

		$rendering['features'] = $features;

		$revisionAttrs = $this->revisionEntitySerializer->toArray(
			$revision,
			self::REVISION_ENTITY_SCHEMA_VERSION
		);
		// revision.rendering contains anything about or derived
		// from the rendering (HTML) of the revision.
		$revisionAttrs['rendering'] = $rendering;

		$eventAttrs = [
			'dt' => self::toDt( $timestamp ),
			'wiki_id' => $wikiId,
			'page' => $this->pageEntitySerializer->toArray( $page, self::PAGE_ENTITY_SCHEMA_VERSION ),
			'revision' => $revisionAttrs,
		];

		$provenance = self::withoutNullValues( [
			// The schema does not allow an empty cause.
			'cause' => $provenanceCause !== '' ? $provenanceCause : null,
			'is_reconciliation_request' => $provenanceIsReconciliationRequest,
		] );
		if ( $provenance ) {
			$eventAttrs['provenance'] = $provenance;
		}

		return $this->eventSerializer->createEvent(
			self::SCHEMA_URI,
			$stream,
			$this->pageEntitySerializer->canonicalPageURL( $page ),
			$eventAttrs,
			$wikiId,
			// Let EventGate set meta.dt.
			null,
			// In a job, this is the ID of the request that enqueued the job.
			Telemetry::getInstance()->getRequestId()
		);
	}

	/**
	 * A timestamp as an event date-time field value.
	 *
	 * @param string|ConvertibleTimestamp|null $timestamp A string must already be an RFC 3339
	 *   date-time. It is not changed.
	 * @return string|null Null if $timestamp is null.
	 */
	private static function toDt( string|ConvertibleTimestamp|null $timestamp ): ?string {
		if ( $timestamp instanceof ConvertibleTimestamp ) {
			return EventSerializer::timestampToDt( $timestamp->getTimestamp() );
		}
		return $timestamp;
	}

	/**
	 * A simple helper function to remove immediate null values from an object.
	 * Event schemas don't allow null; an absent field means that the value is not known.
	 *
	 * @param array<string,mixed> $values
	 * @return array<string,mixed> $values without null values.
	 */
	private static function withoutNullValues( array $values ): array {
		return array_filter( $values, static fn ( $value ): bool => $value !== null );
	}

	/**
	 * Helper function to look up the page's actual wiki_id, even if it returns false for local wiki.
	 * @param ProperPageIdentity $page
	 * @return string
	 */
	private static function getWikiId( ProperPageIdentity $page ): string {
		// A local page's getWikiId() is false.
		return $page->getWikiId() ?: WikiMap::getCurrentWikiId();
	}
}
