<?php
namespace WikimediaEvents\Tests\Integration;

use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Stats\Metrics\CounterMetric;

/**
 * Trait used to de-duplicate code between test classes related to temporary accounts instrumentation classes.
 */
trait TemporaryAccountsInstrumentationTrait {
	/**
	 * Convenience function to assert that the per-wiki counter with the given name
	 * was incremented exactly once.
	 *
	 * @param string $metricName The name of the metric, without the component.
	 * @param string[] $expectedLabels Optional list of additional expected label values.
	 *
	 * @return void
	 */
	protected function assertCounterIncremented( string $metricName, array $expectedLabels = [] ): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'WikimediaEvents' )
			->getCounter( $metricName );

		$samples = $metric->getSamples();

		$this->assertInstanceOf( CounterMetric::class, $metric );
		$this->assertSame( 1, $metric->getSampleCount() );
		$this->assertSame( 1.0, $samples[0]->getValue() );

		$wikiId = WikiMap::getCurrentWikiId();
		$expectedLabels = array_merge(
			[ rtrim( strtr( $wikiId, [ '-' => '_' ] ), '_' ) ],
			$expectedLabels
		);

		$this->assertSame( $expectedLabels, $samples[0]->getLabelValues() );
	}

	/**
	 * Convenience function to assert that the counter with the given name was not incremented.
	 * @param string $metricName
	 * @return void
	 */
	protected function assertCounterNotIncremented( string $metricName ): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'WikimediaEvents' )
			->getCounter( $metricName );

		$this->assertInstanceOf( CounterMetric::class, $metric );
		$this->assertSame( 0, $metric->getSampleCount() );
	}
}
