<?php

namespace WikimediaEvents\Tests;

use FauxRequest;
use RequestContext;
use Title;
use WikimediaEvents\PageSplitter\PageSplitterInstrumentation;
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

	public function provideT307019() {
		return [
			[ 1, 'treatment' ],
			[ 1012765, 'control' ],
			[ 720999, 'treatment' ],
			[ 1575109, 'treatment' ],
			[ 332219, 'control' ],
			[ 496036, 'treatment' ],
			[ 2031011, 'treatment' ],
			[ 4693, 'control' ],
			[ 528228, 'treatment' ],
			[ 341646, 'treatment' ]
		];
	}

	/**
	 * @dataProvider provideT307019
	 */
	public function testT307019( $articleID, $expectedBucket ) {
		// A/B/C split test with 50% sampling.
		$subject = new PageSplitterInstrumentation( 0.5, [ 'a', 'b', 'c' ] );
		$experimentConfig = [
			'name' => 'skin-vector-toc-experiment',
			'enabled' => true,
			'buckets' => [
					'unsampled' => [
							'samplingRate' => 0
					],
					'control' => [
							'samplingRate' => 0.5
					],
					'treatment' => [
							'samplingRate' => 0.5
					],
			]
		];
		$context = $this->createMock( RequestContext::class );
		$context->method( 'getRequest' )->willReturn( new FauxRequest() );
		$title = $this->createMock( Title::class );
		$title->method( 'getArticleID' )->willReturn( $articleID );
		$context->method( 'getTitle' )->willReturn( $title );

		$webABTestArticleIdFactory = new WebABTestArticleIdFactory();
		$bucketKeys = array_keys( $experimentConfig['buckets'] );

		$ab = $webABTestArticleIdFactory->makeWebABTestArticleIdStrategy(
			$webABTestArticleIdFactory->filterExcludedBucket( $bucketKeys ),
			1 - $experimentConfig['buckets']['unsampled']['samplingRate'],
			'tableofcontents',
			$context
		);
		$bucket = $ab->getBucket();
		$this->assertSame( $expectedBucket, $bucket );
	}
}
