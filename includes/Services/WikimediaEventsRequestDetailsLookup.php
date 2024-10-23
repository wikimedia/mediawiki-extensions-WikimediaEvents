<?php

namespace WikimediaEvents\Services;

use RequestContext;

/**
 * Looks up information about the current request, such as the platform and entry point. Used to de-duplicate code.
 */
class WikimediaEventsRequestDetailsLookup {

	/**
	 * @return string A string which represents the entry point (MW_ENTRY_POINT) for use in Prometheus labels
	 */
	public function getEntryPoint(): string {
		$mwEntryPoint = $this->getMWEntryPointConstant();
		if ( $mwEntryPoint === 'index' ) {
			// non-AJAX submission from user interface
			// (for non-WMF this could also mean jobrunner, since jobs run post-send
			// from index.php by default)
			return 'index';
		} elseif ( $mwEntryPoint === 'api' || $mwEntryPoint === 'rest' ) {
			return 'api';
		} else {
			// jobrunner, maint/cli
			return 'other';
		}
	}

	/**
	 * @return string Returns the value of the MW_ENTRY_POINT constant. Mocked in PHPUnit tests.
	 */
	protected function getMWEntryPointConstant(): string {
		return MW_ENTRY_POINT;
	}

	/**
	 * Gets the platform name and whether the device is mobile, for use in Prometheus labels.
	 *
	 * @return string[]
	 */
	public function getPlatformDetails(): array {
		// Because ::getEntryPoint only can use details from the main request, we don't want to support a use-case
		// which has a non-main request context.
		$context = RequestContext::getMain();

		// It's possible to use Minerva on a desktop device, or Vector on a mobile
		// device, but defining Minerva usage as a proxy for "is mobile" is good enough
		// for monitoring.
		$isMobile = $context->getSkin()->getSkinName() === 'minerva' ? '1' : '0';

		// Would make sense to gate the following lines behind $entry === 'api', but
		// the entrypoint is hardcoded via MW_ENTRY_POINT, which can't be overridden in tests.

		// Check if the request was Android/iOS/Commons app.
		$userAgent = $context->getRequest()->getHeader( "User-agent" );
		$isWikipediaApp = strpos( $userAgent, "WikipediaApp/" ) === 0;
		$isCommonsApp = strpos( $userAgent, "Commons/" ) === 0;
		if ( $isWikipediaApp || $isCommonsApp ) {
			// Consider apps to be "mobile" for instrumentation purposes
			$isMobile = '1';
		}
		if ( $isCommonsApp ) {
			$platform = 'commons';
		} elseif ( strpos( $userAgent, "Android" ) > 0 ) {
			$platform = 'android';
		} elseif ( strpos( $userAgent, "iOS" ) > 0 || strpos( $userAgent, "iPadOS" ) > 0 ) {
			$platform = 'ios';
		} elseif ( $this->getEntryPoint() === 'index' ) {
			$platform = 'web';
		} else {
			$platform = 'unknown';
		}

		return [ 'platform' => $platform, 'isMobile' => $isMobile ];
	}
}
