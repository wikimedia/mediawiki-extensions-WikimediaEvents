<?php

namespace WikimediaEvents\EditPage;

use ExtensionRegistry;
use MediaWiki\Api\ApiMessage;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ConfirmEdit\AbuseFilter\CaptchaConsequence;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\hCaptcha\HCaptcha;
use MediaWiki\Extension\ConfirmEdit\Hooks;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Hook\EditPage__attemptSave_afterHook;
use MediaWiki\Request\WebRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Session\Session;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use MessageSpecifier;

/**
 * Hooks for logging hCaptcha risk scores during edit operations.
 */
class CaptchaScoreHooks implements PageSaveCompleteHook, EditPage__attemptSave_afterHook {

	private const STREAM = 'mediawiki.hcaptcha.risk_score';
	private const SCHEMA = '/analytics/mediawiki/hcaptcha/risk_score/1.2.0';

	public function __construct(
		private readonly EventSubmitter $eventSubmitter,
		private readonly UserFactory $userFactory,
		private readonly UserGroupManager $userGroupManager,
		private readonly CentralIdLookup $centralIdLookup,
		private readonly ExtensionRegistry $extensionRegistry,
	) {
	}

	/** @inheritDoc */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		if ( !$this->extensionRegistry->isLoaded( 'ConfirmEdit' ) ) {
			return;
		}

		$hCaptcha = $this->getHCaptchaInstanceForEdits();
		$title = $wikiPage->getTitle();

		if ( !$hCaptcha || !$this->shouldHandleAction( $user, $title, $hCaptcha ) ) {
			return;
		}

		$riskScore = $this->getRiskScore( $hCaptcha, $user );
		$request = RequestContext::getMain()->getRequest();

		// Note that the log_type is not applicable for successful edits,
		// so it is not provided in this call to buildEventPayload().
		$event = $this->buildEventPayload(
			$editResult->isNullEdit() ? 'null_edit' : CaptchaTriggers::EDIT,
			$revisionRecord->getId(),
			'revision',
			$user,
			$riskScore,
			$request
		);

		$this->eventSubmitter->submit( self::STREAM, $event );
	}

	/** @inheritDoc */
	public function onEditPage__attemptSave_after( $editpage_Obj, $status, $resultDetails ): void {
		if ( $status->isGood() || !$this->extensionRegistry->isLoaded( 'ConfirmEdit' ) ) {
			return;
		}

		$hCaptcha = $this->getHCaptchaInstanceForEdits();
		$user = $editpage_Obj->getContext()->getUser();
		$title = $editpage_Obj->getTitle();

		if ( !$hCaptcha || !$this->shouldHandleAction( $user, $title, $hCaptcha ) ) {
			return;
		}

		$riskScore = $this->getRiskScore( $hCaptcha, $user );
		$request = RequestContext::getMain()->getRequest();

		foreach ( $status->getMessages( 'error' ) as $message ) {
			$abuseFilterId = $this->getAbuseFilterId( $message );
			$latestRevision = $editpage_Obj->getExpectedParentRevision();
			$identifier = $latestRevision instanceof RevisionRecord ?
				(int)$latestRevision->getId() : 0;

			$payload = $this->buildEventPayload(
				'failed_edit',
				$identifier,
				'latest_revision',
				$user,
				$riskScore,
				$request,
				$abuseFilterId ? 'abuse_filter' : 'other'
			);

			if ( $abuseFilterId ) {
				$payload['abuse_filter_id'] = $abuseFilterId;
			}

			$this->eventSubmitter->submit( self::STREAM, $payload );
		}
	}

	/**
	 * Build base event payload.
	 */
	private function buildEventPayload(
		string $action,
		int $identifier,
		string $identifierType,
		UserIdentity $user,
		float $riskScore,
		WebRequest $request,
		?string $logType = null,
	): array {
		$event = [
			'$schema' => self::SCHEMA,
			'action' => $action,
			'http' => [
				'method' => $request->getMethod(),
			],
			'identifier' => $identifier,
			'identifier_type' => $identifierType,
			'performer' => $this->getUserEntitySerializer()->toArray( $user ),
			'risk_score' => $riskScore,
			'mw_entry_point' => MW_ENTRY_POINT,
			'wiki_id' => WikiMap::getCurrentWikiId(),
		];

		if ( $logType !== null ) {
			$event['log_type'] = $logType;
		}

		$editingSessionId = $request->getRawVal( 'editingStatsId' );
		if ( $editingSessionId ) {
			$event['editing_session_id'] = $editingSessionId;
		}

		return $event;
	}

	/**
	 * Determines if action that triggered a hook should be handled by this
	 * class; that is, whether the action should result in an event being
	 * logged.
	 *
	 * @param UserIdentity $userIdentity User performing the action
	 * @param Title $title Page the action is performed for.
	 * @param HCaptcha $hCaptcha hCaptcha instance associated with the action.
	 *
	 * @return bool True if the action should be handled, false otherwise.
	 */
	private function shouldHandleAction(
		UserIdentity $userIdentity,
		Title $title,
		HCaptcha $hCaptcha
	): bool {
		$user = $this->userFactory->newFromUserIdentity( $userIdentity );
		$triggersCaptcha = $hCaptcha->triggersCaptcha(
			CaptchaTriggers::EDIT,
			$title
		);

		return $triggersCaptcha && !$hCaptcha->canSkipCaptcha( $user );
	}

	/**
	 * Obtain the hCaptcha instance to be used for edits, or null if hCaptcha is
	 * not configured for edits.
	 */
	private function getHCaptchaInstanceForEdits(): ?HCaptcha {
		$instance = Hooks::getInstance( CaptchaTriggers::EDIT );
		if ( $instance instanceof HCaptcha ) {
			return $instance;
		}

		return null;
	}

	/**
	 * Retrieve the hCaptcha session score safely.
	 *
	 * Returns -1 if a score cannot be retrieved.
	 */
	private function getRiskScore( HCaptcha $hCaptcha, UserIdentity $user ): float {
		$score = $hCaptcha->retrieveSessionScore( 'hCaptcha-score', $user->getName() );
		if ( is_numeric( $score ) ) {
			return (float)$score;
		}

		return -1.0;
	}

	/**
	 * Retrieve the ID of the AbuseFilter that triggered a captcha, if any.
	 *
	 * @param MessageSpecifier $message Error message to get the filter ID for.
	 * @return int|null
	 */
	private function getAbuseFilterId( MessageSpecifier $message ): ?int {
		$errorMessageKey = $message->getKey();

		if ( $message instanceof ApiMessage ) {
			if ( str_starts_with( $errorMessageKey, 'abusefilter-' ) ) {
				$apiData = $message->getApiData();
				return $apiData['abusefilter']['id'] ?? null;
			}
		}

		if ( $errorMessageKey === 'hcaptcha-force-show-captcha-edit' ) {
			// (T410992) This message key is set when a captcha consequence has
			// been triggered, which reloads the edit page asking the user to
			// resubmit it.
			//
			// When that happens, $message does not carry information about the
			// filter that triggered the captcha consequence, so we try to read
			// it from the user session instead.
			return $this->getSession()->get(
				CaptchaConsequence::FILTER_ID_SESSION_KEY
			);
		}

		return null;
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

	/**
	 * Retrieve the user session.
	 */
	private function getSession(): Session {
		return RequestContext::getMain()->getRequest()->getSession();
	}
}
