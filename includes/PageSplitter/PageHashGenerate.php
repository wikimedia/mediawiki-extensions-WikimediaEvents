<?php

namespace WikimediaEvents\PageSplitter;

/**
 * @license GPL-2.0-or-later
 */
class PageHashGenerate {

	/**
	 * Get hash of a page ID as a float between 0.0 (inclusive) and 1.0 (non-inclusive).
	 *
	 * @param int $pageId
	 * @return float
	 */
	public function getPageHash( int $pageId ): float {
		return intval( substr( md5( (string)$pageId ), 0, 6 ), 16 ) / ( 0xffffff + 1 );
	}

}
