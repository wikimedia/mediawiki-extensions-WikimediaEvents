<?php

namespace WikimediaEvents\Tests;

use WikimediaEvents\WebABTest\WebABTestArticleIdFactory;

/**
 * @covers \WikimediaEvents\WebABTest\WebABTestArticleIdFactory
 */
class WebABTestArticleIdFactoryTest extends \MediaWikiUnitTestCase {

	public function testFilterExcludedBucket() {
		$webABTestArticleIdFactory = new WebABTestArticleIdFactory();
		$buckets = $webABTestArticleIdFactory->filterExcludedBucket(
			[ 'control', 'treatment', 'unsampled' ]
		);

		$this->assertEquals( [ 'control', 'treatment' ], $buckets );
	}

	public function testFilterExcludedBucketWhenNoUnsampled() {
		$webABTestArticleIdFactory = new WebABTestArticleIdFactory();
		$buckets = $webABTestArticleIdFactory->filterExcludedBucket(
			[ 'control', 'treatment' ]
		);

		$this->assertEquals( [ 'control', 'treatment' ], $buckets );
	}
}
