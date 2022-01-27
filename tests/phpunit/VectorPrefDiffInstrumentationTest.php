<?php

namespace WikimediaEvents\Tests;

use HTMLForm;
use MediaWiki\User\UserEditTracker;
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
	 * @param bool $shouldIncludeVector2022
	 * @return HTMLForm
	 */
	private function createFormWithDefaultValues( $skinDefault, $skinVersionDefault, $shouldIncludeVector2022 ) {
		$skinField = $this->createMock( \HTMLFormField::class );
		$skinField->method( 'getDefault' )->willReturn( $skinDefault );
		$skinOptions = [
			"Vector ()" => "vector",
			"MinervaNeue ()" => "minerva",
		];

		if ( $shouldIncludeVector2022 ) {
			$skinOptions[ "Vector 2022 (<>)" ] = "vector-2022";
		}
		$skinField->method( 'getOptions' )->willReturn( $skinOptions );
		$skinVersionField = $this->createMock( \HTMLFormField::class );
		$skinVersionField->method( 'getDefault' )->willReturn( $skinVersionDefault );

		$form = $this->createMock( \HTMLForm::class );
		$form->method( 'hasField' )->will(
			$this->returnValueMap( [
				[ 'skin', true ],
				[ 'VectorSkinVersion', true ]
			] )
		);

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

		return $user;
	}

	public function providePrefDiff() {
		$user = $this->createUser();

		yield 'From vector1 to vector2' => [
			[
				'skin' => 'vector',
				'VectorSkinVersion' => '2',
			],
			$this->createFormWithDefaultValues( 'vector', true, false ),
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
			$this->createFormWithDefaultValues( 'vector', false, false ),
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
			$this->createFormWithDefaultValues( 'vector', true, false ),
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
			$this->createFormWithDefaultValues( 'minerva', true, false ),
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
			$this->createFormWithDefaultValues( 'vector', false, false ),
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
			$this->createFormWithDefaultValues( 'minerva', false, false ),
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
			$this->createFormWithDefaultValues( 'minerva', true, false ),
			$user,
			null
		];

		yield 'when `skin` field not present' => [
			[
			],
			$this->createFormWithDefaultValues( 'minerva', true, false ),
			$user,
			null
		];

		yield 'when `VectorSkinVersion` field not present' => [
			[
				'skin' => 'timeless'
			],
			$this->createFormWithDefaultValues( 'minerva', true, false ),
			$user,
			null
		];
		yield 'when `VectorSkinVersion` field is false' => [
			[
				'skin' => 'vector',
				'VectorSkinVersion' => false
			],
			$this->createFormWithDefaultValues( 'minerva', true, false ),
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
			$this->createFormWithDefaultValues( 'minerva', true, false ),
			$user,
			[
				'initial_state' => 'minerva',
				'final_state' => 'vector1',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];
		yield 'when `vector-2022` present and switching from vector1 to vector2' => [
			[
				'skin' => 'vector-2022',
				'VectorSkinVersion' => true
			],
			$this->createFormWithDefaultValues( 'vector', true, true ),
			$user,
			[
				'initial_state' => 'vector1',
				'final_state' => 'vector2',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];
		yield 'when `vector-2022` present and switching from vector2 to vector1' => [
			[
				'skin' => 'vector',
				'VectorSkinVersion' => true
			],
			$this->createFormWithDefaultValues( 'vector-2022', true, true ),
			$user,
			[
				'initial_state' => 'vector2',
				'final_state' => 'vector1',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];
		yield 'when `vector-2022` present and switching from vector2 to minerva' => [
			[
				'skin' => 'minerva',
				'VectorSkinVersion' => true
			],
			$this->createFormWithDefaultValues( 'vector-2022', true, true ),
			$user,
			[
				'initial_state' => 'vector2',
				'final_state' => 'minerva',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];
		yield 'when `vector-2022` present and switching from minerva to vector2' => [
			[
				'skin' => 'vector-2022',
				'VectorSkinVersion' => true
			],
			$this->createFormWithDefaultValues( 'minerva', true, true ),
			$user,
			[
				'initial_state' => 'minerva',
				'final_state' => 'vector2',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];
		yield 'when `vector-2022` present and switching from vector1 to minerva' => [
			[
				'skin' => 'minerva',
				'VectorSkinVersion' => true
			],
			$this->createFormWithDefaultValues( 'vector', true, true ),
			$user,
			[
				'initial_state' => 'vector1',
				'final_state' => 'minerva',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];
		yield 'when `vector-2022` present and switching from minerva to vector1' => [
			[
				'skin' => 'vector',
				'VectorSkinVersion' => true
			],
			$this->createFormWithDefaultValues( 'minerva', true, true ),
			$user,
			[
				'initial_state' => 'minerva',
				'final_state' => 'vector1',
				'bucketed_user_edit_count' => '5-99 edits',
			]
		];
		yield 'when `vector-2022` present and switching from minerva to timeless' => [
			[
				'skin' => 'timeless',
				'VectorSkinVersion' => true
			],
			$this->createFormWithDefaultValues( 'minerva', true, true ),
			$user,
			null
		];
	}

	public function testNullSalt() {
		$this->setMwGlobals( 'wgWMEVectorPrefDiffSalt', null );
		$userEditTracker = $this->createMock( UserEditTracker::class );
		$userEditTracker->method( 'getUserEditCount' )
			->willReturn( 42 );
		$this->setService( 'UserEditTracker', $userEditTracker );

		$subject = TestingAccessWrapper::newFromClass( VectorPrefDiffInstrumentation::class );

		$result = $subject->createEventIfNecessary(
			[
				'skin' => 'vector',
				'VectorSkinVersion' => '2',
			],
			$this->createFormWithDefaultValues( 'minerva', false, false ),
			$this->createUser()
		);

			$this->assertNull( $result );
	}

	/**
	 * @dataProvider providePrefDiff
	 */
	public function testCreateEventIfNecessary( $formData, $form, $user, $expect ) {
		$this->setMwGlobals( 'wgWMEVectorPrefDiffSalt', 'secret' );
		$userEditTracker = $this->createMock( UserEditTracker::class );
		$userEditTracker->method( 'getUserEditCount' )
			->willReturn( 42 );
		$this->setService( 'UserEditTracker', $userEditTracker );

		$subject = TestingAccessWrapper::newFromClass( VectorPrefDiffInstrumentation::class );
		$isFormSuccessful = true;

		$result = $subject->createEventIfNecessary( $formData, $form, $user, $isFormSuccessful, [] );

		if ( is_array( $expect ) ) {
			$this->assertArraySubmapSame( $expect, $result );
			$this->assertArrayHasKey( 'user_hash', $result, '"user_hash" key is present' );
			$this->assertIsString( $result['user_hash'], 'User hash is a string' );
			$this->assertGreaterThan( 0, strlen( $result['user_hash'] ), 'A non-empty Hash string exists' );
		} else {
			$this->assertSame( $expect, $result );
		}
	}

}
