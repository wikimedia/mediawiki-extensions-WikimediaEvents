<?php

namespace WikimediaEvents\Tests;

use MediaWiki\User\UserEditTracker;
use User;
use Wikimedia\TestingAccessWrapper;
use WikimediaEvents\PrefUpdateInstrumentation;

/**
 * @covers \WikimediaEvents\PrefUpdateInstrumentation
 */
class PrefUpdateInstrumentationTest extends \MediaWikiIntegrationTestCase {

	private const MOCK_TIME = '20110401080000';

	public function setUp(): void {
		parent::setUp();
		$this->setMwGlobals( 'wgDefaultSkin', 'fallback' );
	}

	public function providePrefUpdate() {
		$user = $this->createMock( User::class );
		$user->method( 'getId' )->willReturn( 4 );

		yield 'Enable foo' => [ $user, 'foo', '1', false ];

		yield 'Set Minerva skin' => [ $user, 'skin', 'minerva', [
			'version' => '2',
			'userId' => 4,
			'saveTimestamp' => '20110401080000',
			'property' => 'skin',
			'value' => '"minerva"',
			'isDefault' => false,
			'bucketedUserEditCount' => '5-99 edits',
		] ];

		yield 'Set default skin' => [ $user, 'skin', 'fallback', [
			'version' => '2',
			'userId' => 4,
			'saveTimestamp' => '20110401080000',
			'property' => 'skin',
			'value' => '"fallback"',
			'isDefault' => true,
			'bucketedUserEditCount' => '5-99 edits',
		] ];

		yield 'Set new VectorSkinVersion' => [ $user, 'VectorSkinVersion', '2', [
			'version' => '2',
			'userId' => 4,
			'saveTimestamp' => '20110401080000',
			'property' => 'VectorSkinVersion',
			'value' => '"2"',
			'isDefault' => false,
			'bucketedUserEditCount' => '5-99 edits',
		] ];

		yield 'Add to email-blacklist' => [ $user, 'email-blacklist', "31\n4\n159", [
			'version' => '2',
			'userId' => 4,
			'saveTimestamp' => '20110401080000',
			'property' => 'email-blacklist',
			'value' => '3',
			'isDefault' => false,
			'bucketedUserEditCount' => '5-99 edits',
		] ];

		yield 'Clear email-blacklist' => [ $user, 'email-blacklist', "", [
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
	public function testCreatePrefUpdateEvent( $user, $name, $value, $expect ) {
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

	public function providePrefUpdateError() {
		$user = $this->createMock( User::class );

		yield 'Store bogus skin value' => [ $user, 'skin', str_repeat( 'x', 100 ), false,
			'Unexpected value for skin'
		];
	}

	/**
	 * @dataProvider providePrefUpdateError
	 */
	public function testPrefUpdateError( $user, $name, $value, $expect, $error ) {
		$prefUpdate = TestingAccessWrapper::newFromClass( PrefUpdateInstrumentation::class );

		$this->assertSame(
			$expect,
			@$prefUpdate->createPrefUpdateEvent( $user, $name, $value, self::MOCK_TIME )
		);

		// Above we assert the return value (ignoring the log-only error).
		// Below we assert the logged error.
		$this->expectError();
		$this->expectErrorMessage( $error );
		$prefUpdate->createPrefUpdateEvent( $user, $name, $value, self::MOCK_TIME );
	}

}
