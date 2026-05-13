<?php

namespace WikimediaEvents\EditPage;

use MediaWiki\Api\ApiMessage;
use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ConfirmEdit\AbuseFilter\CaptchaConsequence;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\hCaptcha\HCaptcha;
use MediaWiki\Extension\ConfirmEdit\Hooks;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Hook\EditPage__attemptSave_afterHook;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\WebRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Session\Session;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Message\MessageSpecifier;

/**
 * Logging of hCaptcha risk scores for edits and account creations.
 */
class CaptchaScoreHooks implements
	PageSaveCompleteHook,
	EditPage__attemptSave_afterHook,
	LocalUserCreatedHook
{

	private const STREAM = 'mediawiki.hcaptcha.risk_score';
	private const SCHEMA = '/analytics/mediawiki/hcaptcha/risk_score/1.3.0';

	public function __construct(
		private readonly EventSubmitter $eventSubmitter,
		private readonly UserFactory $userFactory,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly UserEntitySerializer $userEntitySerializer,
	) {
	}

	/** @inheritDoc */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		if ( !$this->extensionRegistry->isLoaded( 'ConfirmEdit' ) ) {
			return;
		}

		$hCaptcha = $this->getHCaptchaInstance( CaptchaTriggers::EDIT );
		$title = $wikiPage->getTitle();

		if ( !$hCaptcha || !$this->shouldHandleAction( CaptchaTriggers::EDIT, $user, $hCaptcha, $title ) ) {
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

		$hCaptcha = $this->getHCaptchaInstance( CaptchaTriggers::EDIT );
		$user = $editpage_Obj->getContext()->getUser();
		$title = $editpage_Obj->getTitle();

		if ( !$hCaptcha || !$this->shouldHandleAction( CaptchaTriggers::EDIT, $user, $hCaptcha, $title ) ) {
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

	/** @inheritDoc */
	public function onLocalUserCreated( $user, $autocreated ): void {
		// Skip autocreated accounts, as no form with hCaptcha will have been used for
		// that account creation (as it was done automatically)
		if ( $autocreated || !$this->extensionRegistry->isLoaded( 'ConfirmEdit' ) ) {
			return;
		}

		$hCaptcha = $this->getHCaptchaInstance( CaptchaTriggers::CREATE_ACCOUNT );

		if ( !$hCaptcha || !$this->shouldHandleAction( CaptchaTriggers::CREATE_ACCOUNT, $user, $hCaptcha ) ) {
			return;
		}

		$riskScore = $this->getRiskScore( $hCaptcha, $user );
		$request = RequestContext::getMain()->getRequest();

		$event = $this->buildEventPayload(
			CaptchaTriggers::CREATE_ACCOUNT,
			$user->getId(),
			'account',
			$user,
			$riskScore,
			$request
		);

		$this->eventSubmitter->submit( self::STREAM, $event );
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
			'performer' => $this->userEntitySerializer->toArray( $user ),
			'risk_score' => $riskScore,
			'mw_entry_point' => MW_ENTRY_POINT,
			'wiki_id' => WikiMap::getCurrentWikiId(),
		];

		$isBrowser = $request->getHeader( 'x-is-browser' );
		if ( $isBrowser !== false ) {
			$value = $this->castToNonNegativeInteger( $isBrowser );
			if ( $value !== null ) {
				$event['x_is_browser'] = $value;
			}
		}

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
	 * @param string $action One of {@link CaptchaTriggers}
	 * @param UserIdentity $userIdentity User performing the action
	 * @param HCaptcha $hCaptcha hCaptcha instance associated with the action.
	 * @param Title|null $title Page the action is performed for, if relevant
	 * @return bool True if the action should be handled, false otherwise.
	 */
	private function shouldHandleAction(
		string $action,
		UserIdentity $userIdentity,
		HCaptcha $hCaptcha,
		?Title $title = null
	): bool {
		$user = $this->userFactory->newFromUserIdentity( $userIdentity );
		$triggersCaptcha = $hCaptcha->triggersCaptcha( $action, $title );

		return $triggersCaptcha && !$hCaptcha->canSkipCaptcha( $user );
	}

	/**
	 * Obtain the hCaptcha instance to be used for the specified action, or null
	 * if hCaptcha is not used for that action.
	 */
	private function getHCaptchaInstance( string $action ): ?HCaptcha {
		$instance = Hooks::getInstance( $action );
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

				return $this->castAbuseFilterId(
					$apiData['abusefilter']['id'] ?? null
				);
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
			return $this->castAbuseFilterId(
				$this->getSession()->get(
					CaptchaConsequence::FILTER_ID_SESSION_KEY
				)
			);
		}

		return null;
	}

	/**
	 * Validates if an AbuseFilter ID is valid, returning its value as an
	 * integer if it is, or null if it is not. This is needed because the schema
	 * defines abuse_filter_id as an optional integer.
	 *
	 * @param mixed $value Raw value for the AbuseFilter ID
	 * @return int|null Filter ID, null if $value does not represent a valid ID.
	 */
	private function castAbuseFilterId( mixed $value ): ?int {
		// Cast $value to an integer and ensure it is positive (T416622).
		$intVal = $this->castToNonNegativeInteger( $value );
		return ( $intVal !== null && $intVal > 0 ? $intVal : null );
	}

	/**
	 * Validates if a value represents a valid natural number or zero, returning
	 * its value as an integer if it is, or null if it is not.
	 *
	 * This is needed because the schema defines some fields (such as
	 * abuse_filter_id and x_is_browser) as optional integers, but they may be
	 * initially read as strings instead.
	 *
	 * @param mixed $value Raw value to cast.
	 * @return int|null Integer value, null if $value does not represent an int.
	 */
	private function castToNonNegativeInteger( mixed $value ): ?int {
		if ( !is_numeric( $value ) ) {
			return null;
		}

		// Cast $value to an integer and ensure it's positive or zero (T418505).
		$intVal = (int)$value;
		return ( $intVal >= 0 ? $intVal : null );
	}

	/**
	 * Retrieve the user session.
	 */
	private function getSession(): Session {
		return RequestContext::getMain()->getRequest()->getSession();
	}
}
