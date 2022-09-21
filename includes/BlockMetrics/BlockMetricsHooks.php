<?php

namespace WikimediaEvents\BlockMetrics;

use MediaWiki\Block\Block;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventLogging\EventLogging;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Permissions\Hook\PermissionErrorAuditHook;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Message;
use RequestContext;

/**
 * Hooks related to T303995.
 */
class BlockMetricsHooks implements PermissionErrorAuditHook {

	public const SCHEMA = '/analytics/mediawiki/accountcreation/block/4.0.0';

	/** @var UserFactory */
	private $userFactory;

	/** @var EventFactory */
	private $eventFactory;

	/**
	 * @param UserFactory $userFactory
	 * @param EventFactory $eventFactory
	 */
	public function __construct(
		UserFactory $userFactory,
		EventFactory $eventFactory
	) {
		$this->userFactory = $userFactory;
		$this->eventFactory = $eventFactory;
	}

	/** @inheritDoc */
	public function onPermissionErrorAudit(
		LinkTarget $title,
		UserIdentity $user,
		string $action,
		string $rigor,
		array $errors
	): void {
		// Ignore RIGOR_QUICK checks for performance; those won't check blocks anyway.
		if ( $action === 'createaccount' && $rigor !== PermissionManager::RIGOR_QUICK ) {
			// Possible block error keys from Block\BlockErrorFormatter::getBlockErrorMessageKey()
			$blockedErrorKeys = [
				'blockedtext',
				'autoblockedtext',
				'blockedtext-partial',
				'systemblockedtext',
				'blockedtext-composite'
			];
			// Possible block error keys from GlobalBlocking extension GlobalBlocking::getUserBlockDetails()
			$globalBlockedErrorKeys = [
				'globalblocking-ipblocked',
				'globalblocking-ipblocked-range',
				'globalblocking-ipblocked-xff',
				// WikimediaMessages versions
				'wikimedia-globalblocking-ipblocked',
				'wikimedia-globalblocking-ipblocked-range',
				'wikimedia-globalblocking-ipblocked-xff',
			];
			$isApi = defined( 'MW_API' ) || defined( 'MW_REST_API' );

			$blockedErrorMsgs = $globalBlockedErrorMsgs = [];
			foreach ( $errors as $error ) {
				$errorMsg = Message::newFromSpecifier( $error );
				$errorKey = $errorMsg->getKey();
				if ( in_array( $errorKey, $blockedErrorKeys, true ) ) {
					$blockedErrorMsgs[] = $errorMsg;
				} elseif ( in_array( $errorKey, $globalBlockedErrorKeys, true ) ) {
					$globalBlockedErrorMsgs[] = $errorMsg;
				}
			}
			$allErrorMsgs = array_merge( $blockedErrorMsgs, $globalBlockedErrorMsgs );

			if ( !$allErrorMsgs ) {
				return;
			}

			$user = $this->userFactory->newFromUserIdentity( $user );

			$block = null;
			// Prefer the local block over the global one if both are set. This is somewhat arbitrary.
			if ( $blockedErrorMsgs ) {
				$block = $user->isBlockedFromCreateAccount();
			} elseif ( $globalBlockedErrorMsgs ) {
				$block = $user->getGlobalBlock();
			}

			if ( $block ) {
				$context = RequestContext::getMain();
				foreach ( $allErrorMsgs as $msg ) {
					$msg->setContext( $context )->useDatabase( false )->inLanguage( 'en' );
				}
				$rawExpiry = $block->getExpiry();
				if ( wfIsInfinity( $rawExpiry ) ) {
					$expiry = 'infinity';
				} else {
					$expiry = wfTimestamp( TS_ISO_8601, $rawExpiry );
				}
				$event = [
					'$schema' => self::SCHEMA,
					'block_id' => json_encode( $block->getIdentifier() ),
					// @phan-suppress-next-line PhanTypeMismatchDimFetchNullable
					'block_type' => Block::BLOCK_TYPES[ $block->getType() ] ?? 'other',
					'block_expiry' => $expiry,
					'block_scope' => $blockedErrorMsgs ? 'local' : 'global',
					'error_message_keys' => array_map( static function ( Message $msg ) {
						return $msg->getKey();
					}, $allErrorMsgs ),
					'error_messages' => array_map( static function ( Message $msg ) {
						return $msg->plain();
					}, $allErrorMsgs ),
					'user_ip' => $user->getRequest()->getIP(),
					'is_api' => $isApi,
				];
				$event += $this->eventFactory->createMediaWikiCommonAttrs( $user );
				$this->submitEvent( 'mediawiki.accountcreation_block', $event );
			} else {
				LoggerFactory::getInstance( 'WikimediaEvents' )->warning( 'Could not find block', [
					'errorKeys' => implode( ',', array_map( static function ( Message $msg ) {
						return $msg->getKey();
					}, $allErrorMsgs ) ),
				] );
			}
		}
	}

	/**
	 * PHPUnit test helper that allows mocking out the EventLogging dependency.
	 * @param string $streamName
	 * @param array $event
	 * @return void
	 */
	protected function submitEvent( string $streamName, array $event ): void {
		EventLogging::submit( $streamName, $event );
	}

}
