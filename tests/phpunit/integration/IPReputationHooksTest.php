<?php

namespace WikimediaEvents\Tests\Integration;

use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikimediaEvents\IPReputationHooks;

/**
 * @covers \WikimediaEvents\IPReputationHooks
 */
class IPReputationHooksTest extends MediaWikiIntegrationTestCase {

	public static function provideShouldLogEditEventForUser(): array {
		return [
			'anonymous user' => [
				0,
				false,
				true,
			],
			'authenticated user, no registration date' => [
				1,
				null,
				false,
			],
			'authenticated user, older than threshold' => [
				2,
				'20210406112556',
				false,
			],
			'authenticated user, newer than threshold' => [
				2,
				'20240521112556',
				true,
			]
		];
	}

	/**
	 * @dataProvider provideShouldLogEditEventForUser
	 */
	public function testShouldLogEditEventForUser( $userId, $userRegistration, $expected ) {
		$services = $this->getServiceContainer();
		$ipReputationHooks = TestingAccessWrapper::newFromObject(
			new IPReputationHooks(
				$services->getMainConfig(),
				$services->getFormatterFactory(),
				$services->getHttpRequestFactory(),
				$services->getMainWANObjectCache(),
				$services->getUserFactory(),
				$services->getUserGroupManager(),
				$services->getService( 'EventLogging.EventSubmitter' ),
				static fn () => 'index'
			)
		);
		$user = $this->createPartialMock( User::class, [
			'getId',
			'getRegistration'
		] );
		$user->method( 'getId' )->willReturn( $userId );
		$user->method( 'getRegistration' )->willReturn( $userRegistration );
		// Pin time to be able to deterministically check account registration age thresholds.
		ConvertibleTimestamp::setFakeTime( '20240523120000' );
		$this->assertEquals( $expected, $ipReputationHooks->shouldLogEditEventForUser( $user ) );
	}
}
