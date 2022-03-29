<?php

namespace WikimediaEvents\Tests;

use MediaWikiUnitTestCase;
use WikimediaEvents\PageSplitter\PageRandomGenerate;

class PageRandomLookupTest extends MediaWikiUnitTestCase {
	/**
	 * @covers \WikimediaEvents\PageSplitter\PageRandomGenerate::getPageRandom
	 * @dataProvider providerGetPageRandomGenerate
	 */
	public function testGetPageRandom( $expected, $pageId ) {
		$hashedPage = new PageRandomGenerate();
		$this->assertEquals( $expected, $hashedPage->getPageRandom( $pageId ) );
	}

	public function providerGetPageRandomGenerate() {
		return [
			'Valid: 1' => [ 0.925, 3 ],
			'Valid: 10' => [ 0.203, 30 ],
			'Valid: 100' => [ 0.64, 108 ],
			'Valid: 1000' => [ 0.912, 3803 ],
			'Valid: 10000' => [ 0.188, 88088 ],
			'Valid: 100000' => [ 0.252, 418975 ],
			'Valid: 1000000' => [ 0.599, 5374208 ],
			'Valid: 10000000' => [ 0.653, 67123159 ],
		];
	}
}