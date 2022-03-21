<?php

namespace WikimediaEvents\PageSplitter;

use Config;
use OutputPage;
use Skin;

/**
 * Hooks used for PageSplitter-related logging
 */
class PageSplitterHooks {
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
		if ( $this->isSchemaTreatmentActiveForPageId( $pageId ) ) {
			// Sampled page is in the treatment group.
			$headerItems['max-snippet'] = true;
		} else {
			$tester = $this->getPageSplitterInstrumentation();
			$pageRandom = $this->getPageRandom( $pageId );

			// Sampled page is in the control group.
			if ( $pageRandom !== null && $this->isPageIdSampled( $tester, $pageRandom ) ) {
				$headerItems['max-snippet'] = false;
			}
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
		if ( $this->isSchemaTreatmentActiveForPageId( $title->getArticleID() ) ) {
			// Note that if the section snippet A/B test improves organic search referrals,
			// we should add the 'max-snippet' directive to the robots meta tag in core
			// once the experiment is over and this code is removed from WME.
			$out->setRobotsOptions( [ 'max-snippet' => 400 ] );
		}
	}

	/**
	 * @param int $pageId
	 *
	 * @return bool True if page schema A/B test is active, page is sampled, and page is in the
	 *              treatment bucket.
	 */
	private function isSchemaTreatmentActiveForPageId( int $pageId ) {
		$tester = $this->getPageSplitterInstrumentation();
		$pageRandom = $this->getPageRandom( $pageId );

		return $pageRandom !== null
			&& $this->isPageIdSampled( $tester, $pageRandom )
			&& $tester->getBucket( $pageRandom ) === $this->config->get( 'WMEPageSchemaSplitTestTreatment' );
	}

	/**
	 * @return PageSplitterInstrumentation
	 */
	private function getPageSplitterInstrumentation() {
		$samplingRatio = $this->config->get( 'WMEPageSchemaSplitTestSamplingRatio' );
		$buckets = $this->config->get( 'WMEPageSchemaSplitTestBuckets' );
		return new PageSplitterInstrumentation( $samplingRatio, $buckets );
	}

	/**
	 * @param int $pageId
	 * @return float|null
	 */
	private function getPageRandom( int $pageId ) {
		$pageRandomLookup = new PageRandomGenerate();
		return $pageRandomLookup->getPageRandom( $pageId );
	}

	/**
	 * @param PageSplitterInstrumentation $tester
	 * @param float $pageIdRandom
	 * @return bool True if page is sampled.
	 */
	private function isPageIdSampled( PageSplitterInstrumentation $tester, float $pageIdRandom ) {
		return $tester->isSampled( $pageIdRandom );
	}
}
