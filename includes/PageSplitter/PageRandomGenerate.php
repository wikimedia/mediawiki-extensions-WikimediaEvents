<?php

namespace WikimediaEvents\PageSplitter;

/**
 * Return numeric hash of a page id as a float between 0 and 1.
 *
 * @license GPL-2.0-or-later
 */
class PageRandomGenerate {

	/**
	 * @param int $pageId
	 *
	 * @return float
	 */
	public function getPageRandom( int $pageId ): float {
		$random = intval( substr( md5( (string)$pageId ), 0, 6 ), 16 ) / 16777216;
		return round( $random, 3 );
	}

}
