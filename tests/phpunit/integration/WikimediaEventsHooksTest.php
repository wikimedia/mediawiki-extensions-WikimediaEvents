<?php

namespace WikimediaEvents\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use Skin;
use Wikimedia\Stats\StatsFactory;
use Wikimedia\TestingAccessWrapper;

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
		string $userAgent, string $userType, string $skinName, array $expectedStats
	) {
		$context = RequestContext::getMain();
		$context->getRequest()->setHeader( 'User-agent', $userAgent );
		$skin = $this->createMock( Skin::class );
		$skin->method( 'getSkinName' )->willReturn( $skinName );
		$context->setSkin( $skin );
		TestingAccessWrapper::newFromObject( $context->getTiming() )->entries = [
			'requestStart' => [ 'entryType' => 'mark', 'startTime' => 1.900, 'duration' => 0, ],
			'requestShutdown' => [ 'entryType' => 'mark', 'startTime' => 2.023, 'duration' => 0, ]
		];
		if ( $userType === 'anon' ) {
			$this->disableAutoCreateTempUser();
			$authority = $this->getServiceContainer()->getUserFactory()->newAnonymous();
		} elseif ( $userType === 'named' ) {
			$this->enableAutoCreateTempUser();
			$authority = $this->getTestUser()->getAuthority();
		} elseif ( $userType === 'temp' ) {
			$this->enableAutoCreateTempUser();
			$authority = $this->getServiceContainer()->getTempUserCreator()->create(
				'~2024-1', new FauxRequest()
			)->getUser();
		}
		$statsHelper = StatsFactory::newUnitTestingHelper();
		$this->setService( 'StatsFactory', $statsHelper->getStatsFactory() );
		$this->overrideConfigValues( [ 'DBname' => 'example', 'DBmwschema' => null, 'DBprefix' => '' ] );

		$this->editPage( 'Test', 'Test', '', NS_MAIN, $authority );
		$this->assertArrayContains(
			$expectedStats,
			$statsHelper->consumeAllFormatted()
		);
	}

	public function provideStatsFactoryOnPageSaveComplete(): array {
		return [
			[
				'Commons/0.0 (https://mediawiki.org/wiki/Apps/Commons) Android/0',
				'named',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:example,user:normal,is_mobile:1',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:normal,entry:other',
				]
			],
			[
				'WikipediaApp/0.0 (iOS)',
				'named',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:example,user:normal,is_mobile:1',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:normal,entry:other',
				]
			],
			[
				'WikipediaApp/0.0 (iOS)',
				'temp',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:example,user:temp,is_mobile:1',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:temp,entry:other',
				]
			],
			[
				'WikipediaApp/0.0 (Android)',
				'temp',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:example,user:temp,is_mobile:1',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:temp,entry:other',
				]
			],
			'Unknown platform' => [
				'Firefox/0.0',
				'temp',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:example,user:temp,is_mobile:0',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:temp,entry:other',
				]
			],
			'VisualEditor temp account on mobile' => [
				'Firefox/0.0',
				'temp',
				'minerva',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:example,user:temp,is_mobile:1',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:temp,entry:other',
				]
			],
			'VisualEditor anon on desktop' => [
				'Firefox/0.0',
				'anon',
				'vector',
				[
					'mediawiki.WikimediaEvents_edits_total:1|c|#wiki:example,user:anon,is_mobile:0',
					'mediawiki.WikimediaEvents_editResponseTime_seconds:123|ms|#page:content,user:anon,entry:other',
				]
			],
		];
	}
}
