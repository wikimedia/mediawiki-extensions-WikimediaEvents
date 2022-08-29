<?php

namespace WikimediaEvents\Tests;

use MediaWikiUnitTestCase;
use WikimediaEvents\PageSplitter\PageHashGenerate;

/**
 * @covers \WikimediaEvents\PageSplitter\PageHashGenerate
 */
class PageHashGenerateTest extends MediaWikiUnitTestCase {
	/**
	 * @dataProvider provideGetPageHash
	 */
	public function testGetPageHash( $expected, $pageId ) {
		$hashedPage = new PageHashGenerate();
		$actual = $hashedPage->getPageHash( $pageId );
		// Truncate for easier testing
		$actualTrunc = (float)sprintf( '%.3f', $actual );
		$this->assertSame( $expected, $actualTrunc );
	}

	public static function provideGetPageHash(): array {
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
