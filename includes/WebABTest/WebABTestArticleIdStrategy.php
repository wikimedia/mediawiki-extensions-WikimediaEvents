<?php

namespace WikimediaEvents\WebABTest;

use Title;
use WebRequest;
use WikimediaEvents\PageSplitter\PageRandomGenerate;
use WikimediaEvents\PageSplitter\PageSplitterInstrumentation;

/**
 * Integrates with PageSplitterInstrumentation and PageRandomGenerate to sample
 * and bucket based on article id. Bucket can be overriden with a query param
 * via the $overrideName param.
 */
final class WebABTestArticleIdStrategy {
	public const EXCLUDED_BUCKET_NAME = 'unsampled';

	/**
	 * @var string[]
	 */
	private $buckets;

	/**
	 * @var Title
	 */
	private $title;

	/**
	 * @var WebRequest
	 */
	private $request;

	/**
	 * @var string
	 */
	private $overrideName;

	/**
	 * @var PageSplitterInstrumentation
	 */
	private $pageSplitterInstrumentation;

	/**
	 * @var PageRandomGenerate
	 */
	private $pageRandomGenerate;

	/**
	 * @param string[] $buckets An array of bucket name strings. E.g., ['control',
	 * 'treatment']. If the query param override is suppplied, the returned bucket
	 * will be the index of the buckets array.
	 * @param Title $title
	 * @param WebRequest $request
	 * @param string $overrideName
	 * @param PageSplitterInstrumentation $pageSplitterInstrumentation
	 * @param PageRandomGenerate $pageRandomGenerate
	 */
	public function __construct(
		array $buckets,
		Title $title,
		WebRequest $request,
		string $overrideName,
		PageSplitterInstrumentation $pageSplitterInstrumentation,
		PageRandomGenerate $pageRandomGenerate
	) {
		$this->buckets = $buckets;
		$this->title = $title;
		$this->request = $request;
		$this->overrideName = $overrideName;
		$this->pageSplitterInstrumentation = $pageSplitterInstrumentation;
		$this->pageRandomGenerate = $pageRandomGenerate;
	}

	/**
	 * @return string|null Bucket name or null if a bucket can't be determined.
	 */
	public function getBucket(): ?string {
		// Check if query param exists first.
		if ( $this->request->getCheck( $this->overrideName ) ) {
			$queryParam = $this->request->getBool( $this->overrideName );

			return $this->buckets[ (int)$queryParam ] ?? null;
		}

		$pageRandom = $this->pageRandomGenerate->getPageRandom( $this->title->getArticleID() );

		if ( !$this->pageSplitterInstrumentation->isSampled( $pageRandom ) ) {
			return self::EXCLUDED_BUCKET_NAME;
		}

		return $this->pageSplitterInstrumentation->getBucket( $pageRandom );
	}
}
