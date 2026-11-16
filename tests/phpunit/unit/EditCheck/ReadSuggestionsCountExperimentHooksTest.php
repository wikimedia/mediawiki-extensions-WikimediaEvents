<?php

namespace WikimediaEvents\Tests\Unit\EditCheck;

use MediaWiki\Extension\TestKitchen\Sdk\ExperimentInterface;
use MediaWiki\Extension\TestKitchen\Sdk\ExperimentManagerInterface;
use MediaWiki\Skin\Skin;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use WikimediaEvents\EditCheck\ReadSuggestionsCountExperimentHooks;

/**
 * @covers \WikimediaEvents\EditCheck\ReadSuggestionsCountExperimentHooks
 */
class ReadSuggestionsCountExperimentHooksTest extends MediaWikiUnitTestCase {

	public static function provideIndicator(): iterable {
		yield 'lightbulb' => [ [ 'group' => 'lightbulb' ], 'lightbulb' ];
		yield 'count' => [ [ 'group' => 'count' ], 'count' ];
		yield 'control' => [ [ 'group' => 'control' ], 'hidden' ];
		yield 'control, not eligible' => [ [ 'group' => 'control', 'named' => false ], null ];
		yield 'unknown group' => [ [ 'group' => 'other' ], null ];
		yield 'not enrolled' => [ [ 'group' => null ], null ];
		yield 'temporary or anonymous user' => [ [ 'named' => false ], null ];
		yield 'too many edits' => [ [ 'editCount' => 151 ], null ];
		yield 'maximum edits' => [ [ 'editCount' => 150 ], 'count' ];
		yield 'suggestion mode already enabled' => [ [ 'suggestionsPref' => true ], null ];
		yield 'too many edits, welcome seen' => [ [ 'editCount' => 151, 'welcomePref' => '0' ], 'count' ];
		yield 'too many edits, control would have seen welcome' => [
			[ 'group' => 'control', 'editCount' => 151, 'welcomePref' => '2' ], 'hidden'
		];
		yield 'too many edits, welcome not seen' => [ [ 'editCount' => 151, 'welcomePref' => '1' ], null ];
		yield 'welcome seen, suggestion mode already enabled' => [
			[ 'welcomePref' => '0', 'suggestionsPref' => true ], null
		];
		yield 'minerva' => [ [ 'skin' => 'minerva' ], 'count' ];
		yield 'other skin' => [ [ 'skin' => 'monobook' ], null ];
		yield 'control, other skin' => [ [ 'group' => 'control', 'skin' => 'monobook' ], null ];
	}

	/**
	 * @dataProvider provideIndicator
	 */
	public function testIndicator( array $options, ?string $expected ): void {
		$options += [
			'group' => 'count', 'named' => true, 'editCount' => 10, 'suggestionsPref' => false,
			'skin' => 'vector-2022', 'welcomePref' => null,
		];

		$experiment = $this->createMock( ExperimentInterface::class );
		$experiment->method( 'isAssignedGroup' )->willReturnCallback(
			static fn ( string ...$groups ) => in_array( $options['group'], $groups, true )
		);
		$experiment->method( 'getAssignedGroup' )->willReturn( $options['group'] );
		$experiment->expects( $this->never() )->method( 'sendExposure' );
		$experimentManager = $this->createMock( ExperimentManagerInterface::class );
		$experimentManager->method( 'getExperiment' )
			->with( ReadSuggestionsCountExperimentHooks::EXPERIMENT_NAME )
			->willReturn( $experiment );

		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( $options['named'] );
		$user->method( 'getEditCount' )->willReturn( $options['editCount'] );
		$skin = $this->createMock( Skin::class );
		$skin->method( 'getUser' )->willReturn( $user );
		$skin->method( 'getSkinName' )->willReturn( $options['skin'] );

		$userOptionsLookup = $this->createMock( UserOptionsLookup::class );
		$userOptionsLookup->method( 'getBoolOption' )
			->with( $user, 'visualeditor-editcheck-suggestions' )
			->willReturn( $options['suggestionsPref'] );
		$userOptionsLookup->method( 'getOption' )
			->with( $user, 'visualeditor-editcheck-showdiscoverabilitywelcome' )
			->willReturn( $options['welcomePref'] );

		$indicator = null;
		( new ReadSuggestionsCountExperimentHooks( $experimentManager, $userOptionsLookup ) )
			->onVisualEditorEditCheckReadSuggestionsIndicator( $skin, $indicator );
		$this->assertSame( $expected, $indicator );
	}

	public function testNoExperimentLookupIfNotEligible(): void {
		$experimentManager = $this->createMock( ExperimentManagerInterface::class );
		$experimentManager->expects( $this->never() )->method( 'getExperiment' );
		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( false );
		$skin = $this->createMock( Skin::class );
		$skin->method( 'getUser' )->willReturn( $user );
		$skin->method( 'getSkinName' )->willReturn( 'vector-2022' );

		$indicator = 'count';
		( new ReadSuggestionsCountExperimentHooks(
			$experimentManager, $this->createMock( UserOptionsLookup::class )
		) )->onVisualEditorEditCheckReadSuggestionsIndicator( $skin, $indicator );
		$this->assertNull( $indicator );
	}

}
