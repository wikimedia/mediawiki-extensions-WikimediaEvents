<?php

namespace WikimediaEvents\EditPage;

use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\hCaptcha\HCaptcha;
use MediaWiki\Extension\ConfirmEdit\Hooks;
use MediaWiki\Extension\EventLogging\MetricsPlatform\MetricsClientFactory;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;

/**
 * Hooks for logging hCaptcha risk scores during edit operations
 */
class CaptchaScoreHooks implements PageSaveCompleteHook {

	public function __construct(
		private readonly MetricsClientFactory $metricsClientFactory
	) {
	}

	/** @inheritDoc */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		$simpleCaptcha = Hooks::getInstance( CaptchaTriggers::EDIT );
		if ( !$simpleCaptcha instanceof HCaptcha ||
			!$simpleCaptcha->triggersCaptcha( 'edit', $wikiPage->getTitle() ) ) {
			return;
		}

		$context = RequestContext::getMain();
		$request = $context->getRequest();

		$interactionData = [
			'identifier' => $revisionRecord->getId(),
			'identifier_type' => 'revision',
			'risk_score' => floatval(
				$simpleCaptcha->retrieveSessionScore( 'hCaptcha-score', $user->getName() ) ?? -1
			),
			'mw_entry_point' => MW_ENTRY_POINT,
		];

		$editingSessionId = $request->getRawVal( 'editingStatsId' );
		if ( $editingSessionId ) {
			$interactionData['editing_session_id'] = $editingSessionId;
		}

		$this->submitInteraction( $context, $interactionData );
	}

	/**
	 * Emit an interaction event for hCaptcha risk scores to the Metrics Platform instrument.
	 * @param IContextSource $context
	 * @param array $interactionData Interaction data for the event
	 */
	private function submitInteraction(
		IContextSource $context,
		array $interactionData
	): void {
		$client = $this->metricsClientFactory->newMetricsClient( $context );
		$client->submitInteraction(
			'mediawiki.hcaptcha.risk_score',
			'/analytics/mediawiki/hcaptcha/risk_score/1.0.0',
			'edit',
			$interactionData
		);
	}

}
