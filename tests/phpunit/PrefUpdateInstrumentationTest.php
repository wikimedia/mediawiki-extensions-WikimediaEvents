<?php

namespace WikimediaEvents\Tests;

use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use MediaWiki\User\UserEditTracker;
use Wikimedia\TestingAccessWrapper;
use WikimediaEvents\PrefUpdateInstrumentation;

/**
 * @covers \WikimediaEvents\PrefUpdateInstrumentation
 * @group Database
 */
class PrefUpdateInstrumentationTest extends \MediaWikiIntegrationTestCase {

	private const MOCK_TIME = '20110401080000';

	public function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( MainConfigNames::DefaultSkin, 'fallback' );
	}

	public static function providePrefUpdate() {
		yield 'Enable foo' => [ 'foo', '1', false ];

		yield 'Set Minerva skin' => [ 'skin', 'minerva', [
			'version' => '2',
			'userId' => 4,
			'saveTimestamp' => '20110401080000',
			'property' => 'skin',
			'value' => '"minerva"',
			'isDefault' => false,
			'bucketedUserEditCount' => '5-99 edits',
		] ];

		yield 'Set default skin' => [ 'skin', 'fallback', [
			'version' => '2',
			'userId' => 4,
			'saveTimestamp' => '20110401080000',
			'property' => 'skin',
			'value' => '"fallback"',
			'isDefault' => true,
			'bucketedUserEditCount' => '5-99 edits',
		] ];

		yield 'Set new VectorSkinVersion' => [ 'VectorSkinVersion', '2', [
			'version' => '2',
			'userId' => 4,
			'saveTimestamp' => '20110401080000',
			'property' => 'VectorSkinVersion',
			'value' => '"2"',
			'isDefault' => false,
			'bucketedUserEditCount' => '5-99 edits',
		] ];

		yield 'Add to email-blacklist' => [ 'email-blacklist', "31\n4\n159", [
			'version' => '2',
			'userId' => 4,
			'saveTimestamp' => '20110401080000',
			'property' => 'email-blacklist',
			'value' => '3',
			'isDefault' => false,
			'bucketedUserEditCount' => '5-99 edits',
		] ];

		yield 'Clear email-blacklist' => [ 'email-blacklist', "", [
			'version' => '2',
			'userId' => 4,
			'saveTimestamp' => '20110401080000',
			'property' => 'email-blacklist',
			'value' => '0',
			'isDefault' => true,
			'bucketedUserEditCount' => '5-99 edits',
		] ];
	}

	/**
	 * @dataProvider providePrefUpdate
	 */
	public function testCreatePrefUpdateEvent( $name, $value, $expect ) {
		$user = $this->createMock( User::class );
		$user->method( 'getId' )->willReturn( 4 );
		$userEditTracker = $this->createMock( UserEditTracker::class );
		$userEditTracker->method( 'getUserEditCount' )
			->willReturn( 42 );
		$this->setService( 'UserEditTracker', $userEditTracker );

		$prefUpdate = TestingAccessWrapper::newFromClass( PrefUpdateInstrumentation::class );

		$this->assertSame(
			$expect,
			$prefUpdate->createPrefUpdateEvent( $user, $name, $value, self::MOCK_TIME )
		);
	}

	public static function providePrefUpdateError() {
		yield 'Store bogus skin value' => [ 'skin', str_repeat( 'x', 100 ), false,
			'Unexpected value for skin'
		];
	}

	/**
	 * @dataProvider providePrefUpdateError
	 */
	public function testPrefUpdateError( $name, $value, $expect, $error ) {
		$user = $this->createMock( User::class );
		$prefUpdate = TestingAccessWrapper::newFromClass( PrefUpdateInstrumentation::class );

		$this->assertSame(
			$expect,
			@$prefUpdate->createPrefUpdateEvent( $user, $name, $value, self::MOCK_TIME )
		);

		// Above we assert the return value (ignoring the log-only error).
		// Below we assert the logged error.
		$this->expectPHPError(
			E_USER_WARNING,
			static function () use ( $prefUpdate, $user, $name, $value ) {
				$prefUpdate->createPrefUpdateEvent( $user, $name, $value, self::MOCK_TIME );
			},
			$error
		);
	}

}
