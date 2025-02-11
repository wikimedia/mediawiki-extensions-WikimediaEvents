<?php

namespace WikimediaEvents\EditPage;

use MediaWiki\Hook\EditPage__showReadOnlyForm_initialHook;
use MediaWiki\Permissions\PermissionManager;
use WikimediaEvents\BlockUtils;

/**
 * Hooks related to T310390.
 *
 * we didn't choose hook names, so:
 * @phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
 */
class EditPageHooks implements EditPage__showReadOnlyForm_initialHook {

	/** @var PermissionManager */
	private $permManager;

	/**
	 * @param PermissionManager $permManager
	 */
	public function __construct(
		PermissionManager $permManager
	) {
		$this->permManager = $permManager;
	}

	/** @inheritDoc */
	public function onEditPage__showReadOnlyForm_initial( $editor, $out ) {
		$user = $out->getUser();
		$title = $editor->getTitle();

		// $status = $editor->getEditPermissionStatus( PermissionManager::RIGOR_FULL );
		$status = $this->permManager->getPermissionStatus(
			'edit',
			$user,
			$title,
			PermissionManager::RIGOR_FULL
		);
		$errorMsgs = BlockUtils::getBlockErrorMsgs( $status );

		if ( $errorMsgs['all'] ) {
			BlockUtils::logBlockedEditAttempt( $user, $title, 'wikieditor', 'desktop' );
		}
	}
}
