<?php

namespace WikimediaEvents\Tests;

use HTMLForm;
use MediaWikiIntegrationTestCase;
use User;
use Wikimedia\TestingAccessWrapper;
use WikimediaEvents\VectorPrefDiffInstrumentation;

/**
 * @covers \WikimediaEvents\VectorPrefDiffInstrumentation
 */
class VectorPrefDiffInstrumentationTest extends MediaWikiIntegrationTestCase {
	/**
	 * @param string $skinDefault
	 * @param bool $skinVersionDefault
	 * @return HTMLForm
	 */
	private function createFormWithDefaultValues( $skinDefault, $skinVersionDefault ) {
		$skinField = $this->createMock( \HTMLFormField::class );
		$skinField->method( 'getDefault' )->willReturn( $skinDefault );

		$skinVersionField = $this->createMock( \HTMLFormField::class );
		$skinVersionField->method( 'getDefault' )->willReturn( $skinVersionDefault );

		$form = $this->createMock( \HTMLForm::class );
		$form->method( 'hasField' )->willReturn( true );

		$form->method( 'getField' )->will(
			$this->returnValueMap( [
				[ 'skin', $skinField ],
				[ 'VectorSkinVersion', $skinVersionField ]
			] )
		);

		return $form;
	}

	/**
	 * @return User
	 */
	private function createUser() {
		$user = $this->createMock( User::class );
		$user->method( 'getId' )->willReturn( 1 );
		$user->method( 'getEditCount' )->willReturn( 42 );

		return $user;
	}

	public function providePrefDiff() {
		$user = $this->createUser();

		yield 'From vector1 to vector2' => [
			[
				'skin' => 'vector',
				'VectorSkinVersion' => '2',
			],
			$this->createFormWithDefaultValues( 'vector', true ),
			$user,
			[
				'initial_state' => 'vector1',
				'final_state' => 'vector2',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];

		yield 'From vector2 to vector1' => [
			[
				'skin' => 'vector',
				'VectorSkinVersion' => '1',
			],
			$this->createFormWithDefaultValues( 'vector', false ),
			$user,
			[
				'initial_state' => 'vector2',
				'final_state' => 'vector1',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];

		yield 'From vector1 to minerva' => [
			[
				'skin' => 'minerva',
				'VectorSkinVersion' => '1',
			],
			$this->createFormWithDefaultValues( 'vector', true ),
			$user,
			[
				'initial_state' => 'vector1',
				'final_state' => 'minerva',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];

		yield 'From minerva to vector1' => [
			[
				'skin' => 'vector',
				'VectorSkinVersion' => '1',
			],
			$this->createFormWithDefaultValues( 'minerva', true ),
			$user,
			[
				'initial_state' => 'minerva',
				'final_state' => 'vector1',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];

		yield 'From vector2 to minerva' => [
			[
				'skin' => 'minerva',
				'VectorSkinVersion' => '2',
			],
			$this->createFormWithDefaultValues( 'vector', false ),
			$user,
			[
				'initial_state' => 'vector2',
				'final_state' => 'minerva',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];

		yield 'From minerva to vector2' => [
			[
				'skin' => 'vector',
				'VectorSkinVersion' => '2',
			],
			$this->createFormWithDefaultValues( 'minerva', false ),
			$user,
			[
				'initial_state' => 'minerva',
				'final_state' => 'vector2',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];

		yield 'From minerva to timeless' => [
			[
				'skin' => 'timeless',
				'VectorSkinVersion' => '1',
			],
			$this->createFormWithDefaultValues( 'minerva', true ),
			$user,
			null
		];

		yield 'when `skin` field not present' => [
			[
			],
			$this->createFormWithDefaultValues( 'minerva', true ),
			$user,
			null
		];

		yield 'when `VectorSkinVersion` field not present' => [
			[
				'skin' => 'timeless'
			],
			$this->createFormWithDefaultValues( 'minerva', true ),
			$user,
			null
		];
		yield 'when `VectorSkinVersion` field is false' => [
			[
				'skin' => 'vector',
				'VectorSkinVersion' => false
			],
			$this->createFormWithDefaultValues( 'minerva', true ),
			$user,
			[
				'initial_state' => 'minerva',
				'final_state' => 'vector2',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];
		yield 'when `VectorSkinVersion` field true' => [
			[
				'skin' => 'vector',
				'VectorSkinVersion' => true
			],
			$this->createFormWithDefaultValues( 'minerva', true ),
			$user,
			[
				'initial_state' => 'minerva',
				'final_state' => 'vector1',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];
	}

	public function testNullSalt() {
		$this->setMwGlobals( 'wgWMEVectorPrefDiffSalt', null );
		$subject = TestingAccessWrapper::newFromClass( VectorPrefDiffInstrumentation::class );

		$result = $subject->createEventIfNecessary(
			[
				'skin' => 'vector',
				'VectorSkinVersion' => '2',
			],
			$this->createFormWithDefaultValues( 'minerva', false ),
			$this->createUser()
		);

			$this->assertNull( $result );
	}

	/**
	 * @dataProvider providePrefDiff
	 */
	public function testCreateEventIfNecessary( $formData, $form, $user, $expect ) {
		$this->setMwGlobals( 'wgWMEVectorPrefDiffSalt', 'secret' );
		$subject = TestingAccessWrapper::newFromClass( VectorPrefDiffInstrumentation::class );
		$isFormSuccessful = true;

		$result = $subject->createEventIfNecessary( $formData, $form, $user, $isFormSuccessful, [] );

		if ( is_array( $expect ) && is_array( $result ) ) {
			$this->assertIsArray( $result );
			$this->assertArraySubmapSame( $expect, $result );
			$this->assertArrayHasKey( 'user_hash', $result, '"user_hash" key is present' );
			$this->assertIsString( $result['user_hash'], 'User hash is a string' );
			$this->assertGreaterThan( 0, strlen( $result['user_hash'] ), 'A non-empty Hash string exists' );
		} else {
			$this->assertSame( $expect, $result );
		}
	}

}
