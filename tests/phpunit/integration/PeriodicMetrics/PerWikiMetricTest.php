<?php

namespace WikimediaEvents\Tests\Integration\PeriodicMetrics;

use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\PeriodicMetrics\PerWikiMetric;

/**
 * @covers \WikimediaEvents\PeriodicMetrics\PerWikiMetric
 */
class PerWikiMetricTest extends MediaWikiIntegrationTestCase {
	public function testGetLabels() {
		// Get the object under test with the abstract methods set to have mock values.
		$objectUnderTest = new class() extends PerWikiMetric {
			public function getName(): string {
				return 'test';
			}

			public function calculate(): int {
				return 1;
			}
		};
		// The ::getLabels method should just contain the wiki. Classes which extend it can add additional labels
		// if required.
		$this->assertArrayEquals( [ 'wiki' => WikiMap::getCurrentWikiId() ], $objectUnderTest->getLabels() );
	}
}
