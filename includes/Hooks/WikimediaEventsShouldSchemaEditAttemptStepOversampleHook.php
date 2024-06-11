<?php

namespace WikimediaEvents\Hooks;

use MediaWiki\Context\IContextSource;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "WikimediaEventsShouldSchemaEditAttemptStepOversample" to register handlers
 * implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface WikimediaEventsShouldSchemaEditAttemptStepOversampleHook {
	/**
	 * @param IContextSource $context
	 * @param bool &$shouldOversample
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onWikimediaEventsShouldSchemaEditAttemptStepOversample(
		IContextSource $context,
		bool &$shouldOversample
	);
}
