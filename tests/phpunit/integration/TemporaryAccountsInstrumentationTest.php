<?php
namespace WikimediaEvents\Tests\Integration;

use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Stats\Metrics\CounterMetric;

/**
 * @group Database
 * @covers \WikimediaEvents\TemporaryAccountsInstrumentation
 */
class TemporaryAccountsInstrumentationTest extends MediaWikiIntegrationTestCase {
	public function testShouldTrackPageDeletionRate(): void {
		$page = $this->getExistingTestPage();

		$this->deletePage( $page );

		$wikiId = WikiMap::getCurrentWikiId();

		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'WikimediaEvents' )
			->getCounter( 'users_page_delete_total' );

		$this->assertInstanceOf( CounterMetric::class, $metric );
		$this->assertSame( 1, $metric->getSampleCount() );
		$this->assertSame( 1.0, $metric->getSamples()[0]->getValue() );
		$this->assertSame(
			[ strtr( $wikiId, [ '_' => '', '-' => '_' ] ) ],
			$metric->getSamples()[0]->getLabelValues()
		);
	}
}
