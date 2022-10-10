<?php

namespace WikimediaEvents\PageSplitter;

use Config;

/**
 * Hooks used for PageSplitter-related logging
 */
class PageSplitterHooks {
	private const IS_CONTROL = 1;
	private const IS_TREATMENT = 2;
	private const IS_UNSAMPLED = 3;

	/** @var Config */
	private $config;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Whether page is sampled, A/B test is active, and page is in the treatment bucket.
	 * @param int $pageId
	 * @return int One of IS_CONTROL, IS_TREATMENT or IS_UNSAMPLED
	 */
	private function getActiveInstrumentBucket( int $pageId ): int {
		$instrument = $this->createInstrumentation();
		$pageHash = $this->getPageHash( $pageId );

		if ( !$instrument->isSampled( $pageHash ) ) {
			return self::IS_UNSAMPLED;
		}
		return (
			$instrument->getBucket( $pageHash ) === $this->config->get( 'WMEPageSchemaSplitTestTreatment' )
		) ? self::IS_TREATMENT : self::IS_CONTROL;
	}

	/**
	 * @return PageSplitterInstrumentation
	 */
	private function createInstrumentation(): PageSplitterInstrumentation {
		$samplingRatio = $this->config->get( 'WMEPageSchemaSplitTestSamplingRatio' );
		$buckets = $this->config->get( 'WMEPageSchemaSplitTestBuckets' );
		return new PageSplitterInstrumentation( $samplingRatio, $buckets );
	}

	/**
	 * @param int $pageId
	 * @return float
	 */
	private function getPageHash( int $pageId ): float {
		$lookup = new PageHashGenerate();
		return $lookup->getPageHash( $pageId );
	}
}
