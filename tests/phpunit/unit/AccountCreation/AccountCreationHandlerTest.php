<?php

declare( strict_types = 1 );

namespace WikimediaEvents\Tests\Unit\AccountCreation;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWikiUnitTestCase;
use WikimediaEvents\AccountCreation\AccountCreationHandler;
use WikimediaEvents\AccountCreation\AccountCreationLogger;

/**
 * @covers \WikimediaEvents\AccountCreation\AccountCreationHandler
 */
class AccountCreationHandlerTest extends MediaWikiUnitTestCase {

	public static function provideAccountJustCreatedScenarios(): iterable {
		yield 'not signup' => [
			[
				'returnTo' => 'Foo:Bar',
				'returnToQuery' => 'baz=fizz',
				'type' => '',
			],
			[
				'returnTo' => 'Foo:Bar',
				'returnToQuery' => 'baz=fizz',
			],
			true
		];

		yield 'signup with returnToQuery' => [
			[
				'returnTo' => 'Foo:Bar',
				'returnToQuery' => 'baz=fizz',
				'type' => 'signup',
			],
			[
				'returnTo' => 'Foo:Bar',
				'returnToQuery' => 'baz=fizz&accountJustCreated=1',
			],
			true
		];

		yield 'signup with no returnToQuery' => [
			[
				'returnTo' => 'Foo:Bar',
				'returnToQuery' => '',
				'type' => 'signup',
			],
			[
				'returnTo' => 'Foo:Bar',
				'returnToQuery' => 'accountJustCreated=1',
			],
			true
		];

		yield 'signup with returnToQuery and pre-existing accountJustCreated' => [
			[
				'returnTo' => 'Foo:Bar',
				'returnToQuery' => 'baz=fizz&accountJustCreated=1',
				'type' => 'signup',
			],
			[
				'returnTo' => 'Foo:Bar',
				'returnToQuery' => 'baz=fizz&accountJustCreated=1',
			],
			true
		];
	}

	/**
	 * @dataProvider provideAccountJustCreatedScenarios
	 */
	public function testAccountJustCreatedAdded( array $initial, array $expected, bool $expectedReturnValue ): void {
		$accountCreationHandler = new AccountCreationHandler(
			$this->createNoOpMock( AccountCreationLogger::class ),
			$this->createNoOpMock( ExtensionRegistry::class ),
			$this->createNoOpMock( AuthManager::class ),
		);

		$unused = '';
		[
			'returnTo' => $returnTo,
			'returnToQuery' => $returnToQuery,
			'type' => $type,
		] = $initial;

		$actualReturnValue = $accountCreationHandler->onCentralAuthPostLoginRedirect(
			$returnTo,
			$returnToQuery,
			false,
			$type,
			$unused
		);

		[
			'returnTo' => $expectedReturnTo,
			'returnToQuery' => $expectedReturnToQuery,
		] = $expected;

		$this->assertSame( '', $unused );
		$this->assertSame( $expectedReturnTo, $returnTo );
		$this->assertSame( $expectedReturnToQuery, $returnToQuery );
		$this->assertSame( $expectedReturnValue, $actualReturnValue );
	}
}
