<?php

declare( strict_types = 1 );

namespace WikimediaEvents\EditPage;

use MediaWiki\Block\Block;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ConfirmEdit\Hooks\ConfirmEditHCaptchaRiskScoreRetrievedForBlocksHook;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Extension\GlobalBlocking\GlobalBlock;
use MediaWiki\Request\WebRequest;
use MediaWiki\User\UserIdentity;

/**
 * Logging of hCaptcha risk scores for block-related events.
 *
 * This class is intentionally separate from CaptchaScoreHooks so that it can
 * implement ConfirmEditHCaptchaRiskScoreRetrievedForBlocksHook (defined in
 * ConfirmEdit) without causing a fatal error when ConfirmEdit is not installed.
 * The class is only autoloaded when the hook fires, which only happens when
 * ConfirmEdit is present.
 */
class CaptchaScoreBlocksHook extends AbstractCaptchaScoreHook
	implements ConfirmEditHCaptchaRiskScoreRetrievedForBlocksHook {

	public function __construct(
		private readonly EventSubmitter $eventSubmitter,
		UserEntitySerializer $userEntitySerializer,
	) {
		parent::__construct( $userEntitySerializer );
	}

	/** @inheritDoc */
	public function onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
		float $riskScore,
		array $relevantBlocks,
		UserIdentity $user,
		string $pageViewId,
		$request
	): void {
		foreach ( $relevantBlocks as $block ) {
			$this->handleBlock(
				$block,
				$riskScore,
				$user,
				$pageViewId,
				$request
			);
		}
	}

	private function handleBlock(
		Block $block,
		float $riskScore,
		UserIdentity $user,
		string $pageViewId,
		WebRequest $request
	): void {
		$action = $this->getActionTypeString( $block );
		$blockId = $block->getId();
		if ( $action !== null && $blockId !== null ) {
			$event = $this->buildEventPayload(
				$action,
				$blockId,
				$block instanceof GlobalBlock ? 'global_block' : 'local_block',
				$user,
				$riskScore,
				$request,
				pageViewId: $pageViewId,
			);

			$this->eventSubmitter->submit( self::STREAM, $event );
		}
	}

	private function getActionTypeString( Block $block ): ?string {
		// The same hook serves both the editing and account-creation block
		// flows; the originating page selects the action suffix.
		$title = RequestContext::getMain()->getTitle();
		if ( $title !== null && $title->isSpecial( 'CreateAccount' ) ) {
			return match ( $block->getType() ) {
				Block::TYPE_IP => 'ip_block_account_creation_attempt',
				Block::TYPE_RANGE => 'ip_range_block_account_creation_attempt',
				default => null,
			};
		}

		return match ( $block->getType() ) {
			Block::TYPE_IP => 'ip_block_edit_attempt',
			Block::TYPE_RANGE => 'ip_range_block_edit_attempt',
			Block::TYPE_AUTO => 'auto_block_edit_attempt',
			default => null,
		};
	}
}
