<?php

namespace WikimediaEvents\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use Skin;

/**
 * @covers \WikimediaEvents\WikimediaEventsHooks
 * @group Database
 */
class WikimediaEventsHooksTest extends \MediaWikiIntegrationTestCase {

	use TempUserTestTrait;

	/**
	 * @dataProvider provideStatsFactoryOnPageSaveComplete
	 */
	public function testStatsFactoryOnPageSaveComplete(
		$userAgent, $isUserAnon, $isEditorNamed, $useMinervaSkin, $expectedLabelValues
	) {
		$requestContext = RequestContext::getMain();
		$requestContext->getRequest()->setHeader( 'User-agent', $userAgent );
		if ( $useMinervaSkin ) {
			$skin = $this->createMock( Skin::class );
			$skin->method( 'getSkinName' )->willReturn( 'minerva' );
			$requestContext->setSkin( $skin );
		}
		$timing = $requestContext->getTiming();
		$timing->mark( 'requestStart' );
		$timing->mark( 'requestShutdown' );
		if ( $isUserAnon ) {
			$this->disableAutoCreateTempUser();
			$authority = $this->getServiceContainer()->getUserFactory()->newAnonymous();
		} else {
			$this->enableAutoCreateTempUser();
			$authority = $this->getTestUser()->getAuthority();
			if ( !$isEditorNamed ) {
				$authority = $this->getServiceContainer()->getTempUserCreator()->create(
					'~2024-1', new FauxRequest()
				)->getUser();
			}
		}
		$this->editPage( 'Test', 'Test', '', NS_MAIN, $authority );
		$timer = $this->getServiceContainer()->getStatsFactory()->withComponent( 'WikimediaEvents' )
			->getTiming( 'editResponseTime_seconds' );
		$sample = $timer->getSamples()[0];
		$labelValues = $sample->getLabelValues();
		$this->assertArrayEquals( $expectedLabelValues, $labelValues );
	}

	public function provideStatsFactoryOnPageSaveComplete(): array {
		return [
			[
				'Commons/Blah',
				false,
				true,
				false,
				[ '1', 'commons', 'other', 'normal', 'content' ]
			],
			[
				'WikipediaApp/iOS',
				false,
				true,
				false,
				[ '1', 'ios', 'other', 'normal', 'content' ]
			],
			[
				'WikipediaApp/iOS',
				false,
				false,
				false,
				[ '1', 'ios', 'other', 'temp', 'content' ]
			],
			[
				'WikipediaApp/Android',
				false,
				false,
				false,
				[ '1', 'android', 'other', 'temp', 'content' ]
			],
			[
				'Unknown',
				false,
				false,
				false,
				[ '0', 'unknown', 'other', 'temp', 'content' ]
			],
			[
				'VisualEditor on Minerva',
				false,
				false,
				true,
				[ '1', 'unknown', 'other', 'temp', 'content' ]
			],
			[
				'VisualEditor on Vector',
				true,
				false,
				true,
				[ '1', 'unknown', 'other', 'anon', 'content' ]
			],
		];
	}
}
