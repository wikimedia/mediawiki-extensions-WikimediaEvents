<?php

namespace WikimediaEvents\BlockMetrics;

use MediaWiki\Api\IApiMessage;
use MediaWiki\Block\Block;
use MediaWiki\Extension\CentralAuth\SharedDomainUtils;
use MediaWiki\Extension\EventBus\EventFactory;
use MediaWiki\Extension\EventLogging\EventLogging;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Hook\PermissionStatusAuditHook;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Wikimedia\Message\MessageSpecifier;

/**
 * Hooks related to T303995.
 */
class BlockMetricsHooks implements PermissionStatusAuditHook {

	public const SCHEMA = '/analytics/mediawiki/accountcreation/block/4.1.0';

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
	public function onPermissionStatusAudit(
		LinkTarget $title,
		UserIdentity $user,
		string $action,
		string $rigor,
		PermissionStatus $status
	): void {
		// Ignore RIGOR_QUICK checks for performance; those won't check blocks anyway.
		if ( $action !== 'createaccount' || $rigor === PermissionManager::RIGOR_QUICK ) {
			return;
		}
		$block = $status->getBlock();

		if ( $block ) {
			$isApi = defined( 'MW_API' ) || defined( 'MW_REST_API' );
			$user = $this->userFactory->newFromUserIdentity( $user );
			// Prefer the local block over the global one if both are set (instanceof CompositeBlock).
			// This is somewhat arbitrary, and may not always be correct for other kinds of multi-blocks.
			// (Keep in sync with edit attempt block logging in BlockUtils::logBlockedEditAttempt().)
			$local = !( $block instanceof \MediaWiki\Extension\GlobalBlocking\GlobalBlock );

			$allErrorMsgs = [];
			foreach ( $status->getMessages() as $errorMsg ) {
				// This is a bit ugly, but less than listing all of the error message keys associated with blocks
				if (
					$errorMsg instanceof IApiMessage &&
					in_array( $errorMsg->getApiCode(), [ 'autoblocked', 'blocked' ], true )
				) {
					$allErrorMsgs[] = $errorMsg;
				}
			}

			$rawExpiry = $block->getExpiry();
			if ( wfIsInfinity( $rawExpiry ) ) {
				$expiry = 'infinity';
			} else {
				$expiry = wfTimestamp( TS_ISO_8601, $rawExpiry );
			}

			$sul3Enabled = false;
			if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
				/** @var SharedDomainUtils $sharedDomainUtils */
				$sharedDomainUtils = MediaWikiServices::getInstance()
					->getService( 'CentralAuth.SharedDomainUtils' );
				$sul3Enabled = $sharedDomainUtils->isSul3Enabled( $user->getRequest() );
			}

			$event = [
				'$schema' => self::SCHEMA,
				'block_id' => json_encode( $block->getIdentifier() ),
				// @phan-suppress-next-line PhanTypeMismatchDimFetchNullable
				'block_type' => Block::BLOCK_TYPES[ $block->getType() ] ?? 'other',
				'block_expiry' => $expiry,
				'block_scope' => $local ? 'local' : 'global',
				'error_message_keys' => array_map( static function ( MessageSpecifier $msg ) {
					return $msg->getKey();
				}, $allErrorMsgs ),
				'error_messages' => array_map( static function ( MessageSpecifier $msg ) {
					return wfMessage( $msg )->useDatabase( false )->inLanguage( 'en' )->plain();
				}, $allErrorMsgs ),
				'user_ip' => $user->getRequest()->getIP(),
				'is_api' => $isApi,
				'sul3_enabled' => $sul3Enabled,
			];
			$event += $this->eventFactory->createMediaWikiCommonAttrs( $user );
			$this->submitEvent( 'mediawiki.accountcreation_block', $event );
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
