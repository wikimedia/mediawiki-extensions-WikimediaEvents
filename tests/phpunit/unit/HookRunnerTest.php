<?php

namespace WikimediaEvents\Tests\Unit;

use MediaWiki\Tests\HookContainer\HookRunnerTestBase;
use WikimediaEvents\Hooks\HookRunner;

/**
 * @covers \WikimediaEvents\Hooks\HookRunner
 */
class HookRunnerTest extends HookRunnerTestBase {

	public static function provideHookRunners() {
		yield HookRunner::class => [ HookRunner::class ];
	}
}
