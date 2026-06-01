<?php

declare( strict_types = 1 );

namespace WikimediaEvents\EditPage;

use MediaWiki\Block\AbstractBlock;
use MediaWiki\Block\Block;
use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ConfirmEdit\Hooks\ConfirmEditHCaptchaRiskScoreRetrievedForBlocksHook;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Extension\GlobalBlocking\GlobalBlock;
use MediaWiki\Extension\GlobalBlocking\Services\GlobalBlockLookup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Request\WebRequest;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;

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

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly EventSubmitter $eventSubmitter,
		UserEntitySerializer $userEntitySerializer,
		private readonly DatabaseBlockStore $blockStore,
		private readonly ?GlobalBlockLookup $globalBlockLookup,
	) {
		parent::__construct( $userEntitySerializer );
		$this->logger = LoggerFactory::getInstance( 'WikimediaEvents' );
	}

	/** @inheritDoc */
	public function onConfirmEditHCaptchaRiskScoreRetrievedForBlocks(
		float $riskScore,
		array $localBlockIds,
		array $globalBlockIds,
		UserIdentity $user,
		string $pageViewId,
		$request
	): void {
		foreach ( $localBlockIds as $blockId ) {
			$block = $this->blockStore->newFromID( $blockId );
			if ( !$block ) {
				$this->logger->warning(
					'Local block {blockId} not found when collecting hCaptcha risk scores',
					[ 'blockId' => $blockId ]
				);

				continue;
			}

			$this->handleBlock(
				$block,
				$riskScore,
				$user,
				$pageViewId,
				$request
			);
		}

		if ( $this->globalBlockLookup !== null ) {
			foreach ( $globalBlockIds as $blockId ) {
				$block = $this->globalBlockLookup->newFromId( $blockId );
				if ( !$block ) {
					$this->logger->warning(
						'Global block {blockId} not found when collecting hCaptcha risk scores',
						[ 'blockId' => $blockId ]
					);

					continue;
				}

				$this->handleBlock(
					$block,
					$riskScore,
					$user,
					$pageViewId,
					$request
				);
			}
		}
	}

	private function handleBlock(
		AbstractBlock $block,
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

	private function getActionTypeString( AbstractBlock $block ): ?string {
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
