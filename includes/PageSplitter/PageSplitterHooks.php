<?php

namespace WikimediaEvents\PageSplitter;

use Config;
use OutputPage;
use Skin;

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
	 * On XAnalyticsSetHeader
	 *
	 * When adding new headers here please update the docs:
	 * https://wikitech.wikimedia.org/wiki/X-Analytics
	 *
	 * Insert a 'max-snippet' key with a boolean value if the requested page is bucketed within the control or
	 * treatment group of an experiment. Unsampled pages should not have this key-value pair added.
	 *
	 * @param OutputPage $out
	 * @param array &$headerItems
	 */
	public function onXAnalyticsSetHeader( OutputPage $out, array &$headerItems ): void {
		$title = $out->getTitle();
		if ( !$title || !$title->exists() ) {
			return;
		}

		$pageId = $title->getArticleID();

		// T301584 Add max-snippet key-value pair for sampled pages only.
		$active = $this->getActiveInstrumentBucket( $pageId );
		if ( $active === self::IS_TREATMENT ) {
			// Sampled page is in the treatment group.
			$headerItems['max-snippet'] = true;
		} elseif ( $active === self::IS_CONTROL ) {
			// Sampled page is in the control group.
			$headerItems['max-snippet'] = false;
		}
	}

	/**
	 * BeforePageDisplay hook handler
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 *
	 * Append robots meta tag to the output page.
	 *
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		$title = $out->getTitle();
		if ( !$title || !$title->exists() ) {
			return;
		}

		if ( $this->getActiveInstrumentBucket( $title->getArticleID() ) === self::IS_TREATMENT ) {
			// Note that if the section snippet A/B test improves organic search referrals,
			// we should add the 'max-snippet' directive to the robots meta tag in core
			// once the experiment is over and this code is removed from WME.
			$out->setRobotsOptions( [ 'max-snippet' => 400 ] );
		}
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
		$lookup = new PageRandomGenerate();
		return $lookup->getPageRandom( $pageId );
	}
}
