<?php

namespace WikimediaEvents\Api;

use Diff;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Content\TextContent;
use MediaWiki\Diff\ComplexityException;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\WikiMap\WikiMap;
use UnifiedDiffFormatter;
use Wikimedia\ParamValidator\ParamValidator;

class ApiWikimediaEventsHCaptchaEditAttempt extends ApiBase {

	protected const STREAM = 'mediawiki.hcaptcha.edit';
	protected const SCHEMA = '/analytics/mediawiki/hcaptcha/edit/1.0.0';

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
	 * @throws ComplexityException
	 */
	public function execute(): void {
		$params = $this->extractRequestParams();
		$title = $this->getTitleOrPageId( $params );
		if ( !$title ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['title'] ) ] );
		}

		$page = $this->wikiPageFactory->newFromTitle( $title );
		$user = $this->getUser();

		if ( $user->isAllowed( 'skipcaptcha' ) ) {
			// Refrain from logging if the user has skipcaptcha rights
			$this->dieWithError( 'apierror-wikimediaevents-skipcaptcha-not-applicable' );
		}

		// Enforce rate-limiting
		if ( $user->pingLimiter( 'wikimediaevents-hcaptcha-diff-logging' ) ) {
			$this->dieWithError( 'apierror-ratelimited' );
		}

		$oldText = '';
		$oldContent = $page->getContent();
		if ( $oldContent instanceof TextContent ) {
			$oldText = $oldContent->getText();
		}

		$userEntitySerializer = new UserEntitySerializer(
			$this->userFactory, $this->userGroupManager, $this->centralIdLookup
		);

		$event = [
			'$schema' => $this::SCHEMA,
			'page_id' => $page->getId(),
			'performer' => $userEntitySerializer->toArray( $user ),
			'proposed_content_diff' => $this->generateUnifiedDiff( $oldText, $params['proposed_content'] ),
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
	 * Generate unified diff from old and new text content.
	 * Uses the same approach as AbuseFilter extension.
	 *
	 * @param string $oldText Old content text
	 * @param string $newText New content text
	 * @return string Unified diff string
	 * @throws ComplexityException
	 */
	private function generateUnifiedDiff( string $oldText, string $newText ): string {
		// Split texts into line arrays (same as AbuseFilter)
		$text1 = $oldText === '' ? [] : explode( "\n", $oldText );
		$text2 = $newText === '' ? [] : explode( "\n", $newText );
		// Compute and format as unified diff
		$diffs = new Diff( $text1, $text2 );
		$format = new UnifiedDiffFormatter();
		// Limit size the 8 KB align with the schema expectations.
		return substr( $format->format( $diffs ), 0, 8192 );
	}
}
