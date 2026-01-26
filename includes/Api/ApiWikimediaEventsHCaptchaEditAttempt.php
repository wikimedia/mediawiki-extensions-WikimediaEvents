<?php

namespace WikimediaEvents\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Page\WikiPage;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\WikiMap\WikiMap;
use TextContent;
use Wikimedia\ParamValidator\ParamValidator;

class ApiWikimediaEventsHCaptchaEditAttempt extends ApiBase {

	protected const STREAM = 'mediawiki.hcaptcha.edit';
	protected const SCHEMA = '/analytics/mediawiki/hcaptcha/edit/1.0.0';

	/**
	 * Max size in bytes for the data stored in the proposed_content_diff
	 * property of each event.
	 */
	private const MAX_PAYLOAD_SIZE = 8192;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly UserFactory $userFactory,
		private readonly UserGroupManager $userGroupManager,
		private readonly CentralIdLookup $centralIdLookup,
		private readonly EventSubmitter $eventSubmitter,
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	/**
	 * @inheritDoc
	 * @throws ApiUsageException
	 */
	public function execute(): void {
		$params = $this->extractRequestParams();
		$title = $this->getTitleOrPageId( $params );
		if ( !$title ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['title'] ) ] );
		}

		$user = $this->getUser();

		if ( $user->isAllowed( 'skipcaptcha' ) ) {
			// Refrain from logging if the user has skipcaptcha rights
			$this->dieWithError( 'apierror-wikimediaevents-skipcaptcha-not-applicable' );
		}

		// Enforce rate-limiting
		if ( $user->pingLimiter( 'wikimediaevents-hcaptcha-diff-logging' ) ) {
			$this->dieWithError( 'apierror-ratelimited' );
		}

		$page = $this->wikiPageFactory->newFromTitle( $title );
		$event = [
			'$schema' => $this::SCHEMA,
			'page_id' => $page->getId(),
			'performer' => $this->getUserEntitySerializer()->toArray( $user ),
			'proposed_content_diff' => $this->getContentDiff(
				$page,
				$params['proposed_content'],
			),
			'wiki_id' => WikiMap::getCurrentWikiId(),
			'editing_session_id' => $params['editing_session_id'],
			'revision_id' => $params['revision_id'],
		];

		$this->eventSubmitter->submit( $this::STREAM, $event );
		$this->getResult()->addValue( null, 'result', 'success' );
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams(): array {
		return [
			'title' => [ ParamValidator::PARAM_REQUIRED => true ],
			'proposed_content' => [ ParamValidator::PARAM_REQUIRED => true ],
			'editing_session_id' => [ ParamValidator::PARAM_REQUIRED => true ],
			'revision_id' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'integer'
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function isInternal(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function mustBePosted(): bool {
		return true;
	}

	/**
	 * Generates the diff corresponding to a proposed new content.
	 *
	 * @param WikiPage $page Page the new content belongs to.
	 * @param string $newText Proposed new content
	 * @return string
	 */
	private function getContentDiff( WikiPage $page, string $newText ): string {
		$context = new DerivativeContext( $this->getContext() );
		$context->setTitle( $page->getTitle() );

		$oldContent = $page->getContent();
		$handler = $page->getContentHandler();

		$newContent = $handler->unserializeContent(
			$newText,
			$oldContent !== null ?
				$oldContent->getDefaultFormat() :
				$handler->getDefaultFormat()
		);

		$engine = $handler->createDifferenceEngine( $context );
		$engine->setContent(
			$oldContent ?? new TextContent( '' ),
			$newContent
		);

		// getDiff() returns the inner HTML of a table.
		$diffRenderer = $handler->getSlotDiffRenderer( $context );
		$htmlDiff = $diffRenderer->getDiff( $oldContent, $newContent );
		if ( $htmlDiff === false ) {
			return '';
		}

		return $this->formatDiffPayload( $htmlDiff );
	}

	/**
	 * Returns the payload provided by the diff renderer in a way suitable to
	 * be stored as part of the event payload.
	 *
	 * @param string $diff Diff data provided by SlotDiffRenderer::getDiff().
	 * @return string Payload to use for the content diff of events.
	 */
	private function formatDiffPayload( string $diff ): string {
		$diff = trim( $diff );
		if ( !str_starts_with( $diff, '<tr' ) ) {
			// The diff is not formatted as a table: exit early.
			return substr( $diff, 0, self::MAX_PAYLOAD_SIZE );
		}

		// $diff contains the inner HTML for a table; that is, a string with the
		// code corresponding to all its rows ("<tr>..</tr>" tags concatenated
		// together), without table opening & closing tags (<table> & </table>).
		//
		// Given the schema has a max size of 8kB, we need to make sure that
		// data fits in the available space. Note 15 is the length of the table
		// opening and closing tags (i.e. "<table></table>").
		$expectedLen = 15 + strlen( $diff );
		if ( $expectedLen < self::MAX_PAYLOAD_SIZE ) {
			return "<table>{$diff}</table>";
		}

		return $this->trimDiffTable( $diff );
	}

	/**
	 * Trims the diff table in order to not exceed the available space in each
	 * event, as indicated by self::MAX_PAYLOAD_SIZE.
	 *
	 * This method assumes the length of $diff already exceeds MAX_PAYLOAD_SIZE,
	 * and works by iteratively adding rows from the original diff table to a
	 * buffer until it reaches the max allowed payload size (taking into account
	 * additional opening and closing tags for the table).
	 *
	 * @param string $diffTableHTML Contents of the diff table untrimmed.
	 * @return string
	 */
	private function trimDiffTable( string $diffTableHTML ): string {
		/**
		 * The current length of the body returned by this method.
		 *
		 * Initially it holds the len of the table opening and closing tags
		 * (15 bytes) plus the size of the row indicating the content is
		 * truncated (~40 bytes).
		 */
		$len = 55;
		$body = '';

		foreach ( explode( "</tr>", $diffTableHTML ) as $row ) {
			// Remove additional padding (i.e. new line chars)
			// and put back the closing tag.
			$newRow = trim( $row ) . '</tr>';
			$rowLen = strlen( $newRow );

			// If adding the current row would overflow the available size,
			// add a row with an ellipsis character to indicate that the diff
			// content is truncated.
			if ( $len + $rowLen > self::MAX_PAYLOAD_SIZE ) {
				$numCols = substr_count( $newRow, '<td' );
				$endMarker = sprintf(
					'<tr><td colspan="%d">&hellip;</td></tr>',
					$numCols * 2
				);

				if ( $len + strlen( $endMarker ) < self::MAX_PAYLOAD_SIZE ) {
					$body .= $endMarker;
				}

				break;
			}

			$len += $rowLen;
			$body .= $newRow;
		}

		return "<table>{$body}</table>";
	}

	/**
	 * Retrieve an instance of the UserEntitySerializer service.
	 */
	private function getUserEntitySerializer(): UserEntitySerializer {
		return new UserEntitySerializer(
			$this->userFactory,
			$this->userGroupManager,
			$this->centralIdLookup
		);
	}
}
