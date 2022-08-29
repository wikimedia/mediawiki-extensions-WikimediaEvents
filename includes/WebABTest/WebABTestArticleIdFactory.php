<?php

namespace WikimediaEvents\WebABTest;

use IContextSource;
use WikimediaEvents\PageSplitter\PageHashGenerate;
use WikimediaEvents\PageSplitter\PageSplitterInstrumentation;

/**
 * Factory class that can be shared as a service so that the
 * WebABTestArticleIdStrategy can be instantiated easily outside of this
 * extension.
 */
final class WebABTestArticleIdFactory {
	/**
	 * @param string[] $buckets An array of bucket name strings.
	 * @return string[] Array of bucket name string excluding the `excluded bucket`.
	 */
	public function filterExcludedBucket( $buckets ): array {
		return array_values(
			array_filter( $buckets, static function ( $bucket ) {
				return $bucket !== WebABTestArticleIdStrategy::EXCLUDED_BUCKET_NAME;
			}, ARRAY_FILTER_USE_BOTH )
		);
	}

	/**
	 * @param string[] $buckets An array of bucket name strings. E.g., ['control',
	 * 'treatment']. If the query param override is suppplied, the returned bucket
	 * will be the index of the buckets array.
	 * @param float $samplingRatio
	 * @param string $overrideName Name of query param override.
	 * @param IContextSource $context
	 * @return ?WebABTestArticleIdStrategy
	 */
	public function makeWebABTestArticleIdStrategy(
		array $buckets,
		float $samplingRatio,
		string $overrideName,
		IContextSource $context
	): ?WebABTestArticleIdStrategy {
		$title = $context->getTitle();

		if ( !$title ) {
			return null;
		}

		$request = $context->getRequest();

		return new WebABTestArticleIdStrategy(
			$buckets,
			$title,
			$request,
			$overrideName,
			new PageSplitterInstrumentation( $samplingRatio, $buckets ),
			new PageHashGenerate()
		);
	}
}
