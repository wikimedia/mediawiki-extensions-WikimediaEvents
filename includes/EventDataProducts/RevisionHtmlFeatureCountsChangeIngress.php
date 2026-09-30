<?php

declare( strict_types=1 );

namespace WikimediaEvents\EventDataProducts;

use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\LinkedArtifacts\RevisionLinkedArtifactPrefetchedEvent;
use MediaWiki\Extension\EventBus\LinkedArtifacts\RevisionLinkedArtifactPrefetchedListener;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\RevisionEntitySerializer;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Event\PageLatestRevisionChangedEvent;
use MediaWiki\Page\PageLookup;
use MediaWiki\Revision\RevisionLookup;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Produces mediawiki.revision_html_feature_counts_change events via EventBus after MediaWiki
 * prefetches the revision_feature_counts Linked Artifact of a revision because of a page change (edit).
 *
 * The emitted stream is a changelog of feature counts for a revision. Each event is the
 * feature counts of one revision that MediaWiki prefetched.
 * While unusual, it is possible for a specific revision to have more than one event in the stream.
 * E.g.
 * - if a page is undeleted, its latest revision gets an event.
 * - after a reconciliation request (e.g. a null edit). provenance.is_reconciliation_request is true.
 * - If we one day emit due to HTML ParserCache rendering update events
 * etc.
 *
 * Currently, only LAC prefetch events that a PageLatestRevisionChanged event triggered produce an event to EventBus.
 * Other code can request prefetches too, with its own trigger. For example, on a wiki with
 * FlaggedRevs, a reader might request a prefetch for the artifact of an older, published revision. Those
 * prefetches are not about a page change, so this stream does not include them.
 *
 * @unstable
 */
class RevisionHtmlFeatureCountsChangeIngress
	extends DomainEventIngress
	implements RevisionLinkedArtifactPrefetchedListener
{
	public const STREAM_NAME_DEFAULT = 'mediawiki.revision_html_feature_counts_change.v1';

	/**
	 * The LAC artifact name that will be used for filtering RevisionLinkedArtifactPrefetchedEvents.
	 * This should be a key in LinkedArtifactsConfig artifacts.
	 */
	public const ARTIFACT_NAME = 'revision_feature_counts';

	private readonly string $streamName;
	private readonly RevisionRenderingFeatureCountsChangeEventSerializer $serializer;
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly EventBusFactory $eventBusFactory,
		StreamNameMapper $streamNameMapper,
		EventSerializer $eventSerializer,
		PageEntitySerializer $pageEntitySerializer,
		RevisionEntitySerializer $revisionEntitySerializer,
		private readonly PageLookup $pageLookup,
		private readonly RevisionLookup $revisionLookup
	) {
		$this->streamName = $streamNameMapper->resolve( self::STREAM_NAME_DEFAULT );
		$this->logger = LoggerFactory::getInstance( 'WikimediaEvents' );
		$this->serializer = new RevisionRenderingFeatureCountsChangeEventSerializer(
			$eventSerializer,
			$pageEntitySerializer,
			$revisionEntitySerializer,
			$this->logger
		);
	}

	public function handleRevisionLinkedArtifactPrefetchedEvent(
		RevisionLinkedArtifactPrefetchedEvent $event
	): void {
		// We are only interested in revision_feature_counts artifacts.
		if ( $event->getArtifactName() !== self::ARTIFACT_NAME ) {
			return;
		}

		$logContext = [
			'artifact_name' => $event->getArtifactName(),
			'wiki_id' => $event->getWikiId(),
			'page_id' => $event->getPageId(),
			'revision_id' => $event->getRevisionId(),
		];

		// Produce events only for prefetches that a page change triggered. It is possible for
		// prefetch jobs to happen for other reasons
		// (e.g. a reader submits one in order to prefetch an older revision).
		if ( $event->getTriggerEventType() !== PageLatestRevisionChangedEvent::TYPE ) {
			$this->logger->debug(
				'Not producing {stream} event for a prefetch with trigger {trigger_event_type}',
				[
					'stream' => $this->streamName,
					'trigger_event_type' => $event->getTriggerEventType(),
				] + $logContext
			);
			return;
		}

		// NOTE: Reconciliation requests (e.g. null edits) will produce events,
		// provenance.is_reconciliation_request tells consumers about these events.
		//
		// NOTE: After a null edit, html will eventually be re-rendered.
		// But, nothing orders the re-rendering of a revision's HTML (e.g. in the parser cache)
		// before the LAC prefetch. The lambda might compute the counts from the old cached HTML
		// for the revision. Then the result might have recomputed the same counts as before, but
		// with a newer compute_dt. render_id shows which rendering the counts came from.
		// A fix would be to prefetch after the HTML re-render, e.g. on a 'ParserCache rendering updated'
		// signal, instead of on the page change.

		// A body that does not match the artifact contract is a bug in the lambda or in the
		// LAC config, so throw. DeferredUpdates logs the exception.
		$artifact = $event->getJsonBody();
		if ( $artifact === null ) {
			throw new UnexpectedValueException(
				"The body of the {$event->artifactDescription()} is not a JSON object"
			);
		}

		// Use only revision.rendering from the artifact. The page and revision fields
		// of the event come from the database.
		$artifactRevision = self::arrayOrEmpty( $artifact['revision'] ?? null );
		$rendering = self::arrayOrEmpty( $artifactRevision['rendering'] ?? null );
		$features = self::arrayOrEmpty( $rendering['features'] ?? null );
		$artifactFeatureCounts = $features['counts'] ?? null;

		// Empty counts are valid: the lambda found no features. JSON {} and [] both decode to [].
		if (
			!is_array( $artifactFeatureCounts ) ||
			( $artifactFeatureCounts !== [] && array_is_list( $artifactFeatureCounts ) )
		) {
			throw new UnexpectedValueException(
				"The body of the {$event->artifactDescription()} has no revision.rendering.features.counts object"
			);
		}

		$page = $this->pageLookup->getPageById( $event->getPageId() );
		if ( !$page ) {
			$this->logSkip( 'info', 'page does not exist', $logContext );
			return;
		}

		$revision = $this->revisionLookup->getRevisionById( $event->getRevisionId() );
		if ( !$revision ) {
			$this->logSkip( 'info', 'revision does not exist', $logContext );
			return;
		}
		// Emit the event even if the revision is now on another page, or its content is hidden:
		// the counts were previously computed from the revision's HTML, and they do not
		// expose its content.

		// All of these are optional. A value with the wrong type causes a TypeError.
		$softwareInfo = self::arrayOrEmpty( $features['software_info'] ?? null );
		$content = self::arrayOrEmpty( $rendering['content'] ?? null );
		$eventAttrs = $this->serializer->toEvent(
			$this->streamName,
			$page,
			$revision,
			// dt is when MediaWiki recorded the feature counts state: the prefetch time.
			$event->getEventTimestamp(),
			// compute_dt is when the counts were computed. On a cache hit, e.g. for a
			// restored revision, this is earlier than dt.
			$event->getArtifactComputeTimestamp(),
			$artifactFeatureCounts,
			$softwareInfo['name'] ?? null,
			$softwareInfo['version'] ?? null,
			// rendering and content fields represent the rendering the counts were computed from.
			$rendering['render_id'] ?? null,
			$rendering['render_dt'] ?? null,
			$content['content_length'] ?? null,
			$content['content_type'] ?? null,
			$content['content_language'] ?? null,
			$content['content_uri'] ?? null,
			// The cause of the prefetch trigger is the provenance cause of the event.
			$event->getTriggerCause(),
			$event->isReconciliationRequest()
		);

		// DomainEvent listeners already run in a DeferredUpdate,
		// so there is no need to defer the send again.
		$this->eventBusFactory->getInstanceForStream( $this->streamName )->send( [ $eventAttrs ] );
	}

	/**
	 * Helper to log messages about skipping events
	 */
	private function logSkip( string $level, string $reason, array $logContext ): void {
		$this->logger->log(
			$level,
			'Not producing {stream} event: {reason}',
			[ 'stream' => $this->streamName, 'reason' => $reason ] + $logContext
		);
	}

	/**
	 * If $value is an array, keep it, else return an empty array.
	 *
	 * @param mixed $value
	 * @return array
	 */
	private static function arrayOrEmpty( $value ): array {
		return is_array( $value ) ? $value : [];
	}
}
