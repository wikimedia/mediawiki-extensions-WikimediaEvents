<?php

namespace WikimediaEvents\EditPage;

use ExtensionRegistry;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\hCaptcha\HCaptcha;
use MediaWiki\Extension\ConfirmEdit\Hooks;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\WikiMap\WikiMap;

/**
 * Hooks for logging hCaptcha risk scores during edit operations
 */
class CaptchaScoreHooks implements PageSaveCompleteHook {

	protected const STREAM = 'mediawiki.hcaptcha.risk_score';
	protected const SCHEMA = '/analytics/mediawiki/hcaptcha/risk_score/1.1.0';

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

		$simpleCaptcha = Hooks::getInstance( CaptchaTriggers::EDIT );
		if ( !$simpleCaptcha instanceof HCaptcha ||
			!$simpleCaptcha->triggersCaptcha( 'edit', $wikiPage->getTitle() ) ||
			$simpleCaptcha->canSkipCaptcha( $this->userFactory->newFromUserIdentity( $user ) ) ) {
			return;
		}

		$context = RequestContext::getMain();
		$request = $context->getRequest();
		$userEntitySerializer = new UserEntitySerializer(
			$this->userFactory, $this->userGroupManager, $this->centralIdLookup
		);

		$score = $simpleCaptcha->retrieveSessionScore( 'hCaptcha-score', $user->getName() );
		$riskScore = $score === null || $score === false ? -1.0 : floatval( $score );

		$event = [
			'$schema' => self::SCHEMA,
			'action' => $editResult->isNullEdit() ? 'null_edit' : CaptchaTriggers::EDIT,
			'wiki_id' => WikiMap::getCurrentWikiId(),
			'identifier' => $revisionRecord->getId(),
			'identifier_type' => 'revision',
			'performer' => $userEntitySerializer->toArray( $user ),
			'http' => [
				'method' => $request->getMethod(),
			],
			'risk_score' => $riskScore,
			'mw_entry_point' => MW_ENTRY_POINT,
		];

		$editingSessionId = $request->getRawVal( 'editingStatsId' );
		if ( $editingSessionId ) {
			$event['editing_session_id'] = $editingSessionId;
		}
		$this->eventSubmitter->submit( self::STREAM, $event );
	}

}
