<?php

namespace WikimediaEvents\Hooks;

use IContextSource;
use MediaWiki\HookContainer\HookContainer;

/**
 * This is a hook runner class, see docs/Hooks.md in core.
 * @internal
 */
class HookRunner implements
	WikimediaEventsShouldSchemaEditAttemptStepOversampleHook
{
	private HookContainer $hookContainer;

	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
	}

	/**
	 * @inheritDoc
	 */
	public function onWikimediaEventsShouldSchemaEditAttemptStepOversample(
		IContextSource $context,
		bool &$shouldOversample
	) {
		return $this->hookContainer->run(
			'WikimediaEventsShouldSchemaEditAttemptStepOversample',
			[ $context, &$shouldOversample ]
		);
	}
}
