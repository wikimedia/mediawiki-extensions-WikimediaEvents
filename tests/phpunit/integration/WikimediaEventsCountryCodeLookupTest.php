<?php

namespace WikimediaEvents\Tests\Integration;

use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \WikimediaEvents\WikimediaEventsCountryCodeLookup
 */
class WikimediaEventsCountryCodeLookupTest extends MediaWikiIntegrationTestCase {

	public function testServiceInstantiationWithoutConfig() {
		$this->overrideConfigValue( 'WMEGeoIP2Path', null );
		$wrapper = TestingAccessWrapper::newFromObject(
			$this->getServiceContainer()->getService( 'WikimediaEventsCountryCodeLookup' )
		);
		$this->assertNull(
			$wrapper->reader
		);
	}

	public function testServiceInstantiationWithInvalidPath() {
		$this->overrideConfigValue( 'WMEGeoIP2Path', $this->getNewTempFile() );
		$wrapper = TestingAccessWrapper::newFromObject(
			$this->getServiceContainer()->getService( 'WikimediaEventsCountryCodeLookup' )
		);
		$this->assertNull(
			$wrapper->reader
		);
	}

}
