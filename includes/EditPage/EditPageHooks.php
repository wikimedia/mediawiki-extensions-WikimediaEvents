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

		// $errors = $editor->getEditPermissionErrors( PermissionManager::RIGOR_FULL );
		$errors = $this->permManager->getPermissionErrors(
			'edit',
			$user,
			$title,
			PermissionManager::RIGOR_FULL,
			[]
		);
		$errorMsgs = BlockUtils::getBlockErrorMsgs( $errors );

		if ( $errorMsgs['all'] ) {
			BlockUtils::logBlockedEditAttempt( $user, $title, 'wikieditor', 'desktop' );
		}

		return true; // ignored
	}
}
