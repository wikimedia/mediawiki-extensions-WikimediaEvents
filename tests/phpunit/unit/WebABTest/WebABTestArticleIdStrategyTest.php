<?php

namespace WikimediaEvents\Tests;

use Title;
use WebRequest;
use WikimediaEvents\PageSplitter\PageHashGenerate;
use WikimediaEvents\PageSplitter\PageSplitterInstrumentation;
use WikimediaEvents\WebABTest\WebABTestArticleIdStrategy;

/**
 * @covers \WikimediaEvents\WebABTest\WebABTestArticleIdStrategy
 */
class WebABTestArticleIdStrategyTest extends \MediaWikiUnitTestCase {

	public function provideGetBucketData() {
		return [
			[
				// query param value
				null,
				// $pageSplitterInstrumentationIsSampledValue - The value that page
				// splitter instrumentation returns for the isSampled method.
				true,
				// $pageSplitterInstrumentationValue - The value that page splitter
				// instrumentation returns for the getBucket method
				'unsampled',
				// expected
				'unsampled'
			],
			[
				null,
				true,
				'control',
				'control'
			],
			[
				null,
				true,
				'treatment',
				'treatment'
			],
			[
				false,
				true,
				'treatment',
				'control'
			],
			[
				true,
				true,
				'control',
				'treatment'
			],
			[
				null,
				false,
				'treatment',
				'unsampled'
			]
		];
	}

	/**
	 * @dataProvider provideGetBucketData
	 */
	public function testGetBucket(
		?bool $queryParamValue,
		bool $pageSplitterInstrumentationIsSampledValue,
		string $pageSplitterInstrumentationValue,
		?string $expected
	) {
		$title = $this->createStub( Title::class );
		$title->method( 'getArticleId' )->willReturn( 1 );

		$request = $this->createMock( WebRequest::class );
		$request->method( 'getCheck' )->willReturn( $queryParamValue !== null );
		$request->method( 'getBool' )->willReturn( $queryParamValue ?? false );

		$pageSplitterInstrumentation = $this->createMock( PageSplitterInstrumentation::class );
		$pageSplitterInstrumentation->method( 'getBucket' )->willReturn( $pageSplitterInstrumentationValue );
		$pageSplitterInstrumentation->method( 'isSampled' )->willReturn( $pageSplitterInstrumentationIsSampledValue );

		$PageHashGenerate = $this->createMock( PageHashGenerate::class );
		$PageHashGenerate->method( 'getPageHash', 0.5 );

		$ab = new WebABTestArticleIdStrategy(
			[ 'control', 'treatment' ],
			$title,
			$request,
			'queryOverride',
			$pageSplitterInstrumentation,
			$PageHashGenerate
		);

		$bucket = $ab->getBucket();

		$this->assertEquals( $expected, $bucket );
	}
}
