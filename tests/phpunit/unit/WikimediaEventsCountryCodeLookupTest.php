<?php

namespace WikimediaEvents\Tests\Unit;

use GeoIp2\Database\Reader;
use GeoIp2\Model\Country as ModelCountry;
use MediaWiki\Request\FauxRequest;
use MediaWikiUnitTestCase;
use WikimediaEvents\WikimediaEventsCountryCodeLookup;

/**
 * @covers \WikimediaEvents\WikimediaEventsCountryCodeLookup
 */
class WikimediaEventsCountryCodeLookupTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideGetCountryCodeFromCookieTestCases
	 */
	public function testGetCountryCodeFromCookie(
		$expectedCountryCode, ?string $geoIpCookie
	) {
		$webRequest = new FauxRequest();
		$webRequest->setCookie( 'GeoIP', $geoIpCookie, '' );
		$this->assertEquals(
			$expectedCountryCode,
			WikimediaEventsCountryCodeLookup::getFromCookie( $webRequest )
		);
	}

	public static function provideGetCountryCodeFromCookieTestCases(): array {
		return [
			'Country code is present in expected format' => [
				'DE',
				'DE:blah',
			],
			'GeoIP cookie not found' => [
				null,
				null,
			],
		];
	}

	/**
	 * @dataProvider provideGetCountryCodeTestCases
	 */
	public function testGetCountryCode(
		$expectedCountryCode, ?string $readerCountryCode, string $ip
	) {
		$readerMock = $this->createMock( Reader::class );

		$countryModelMock = $this->getMockBuilder( ModelCountry::class )
			->setConstructorArgs( [ [
				'country' => [ 'iso_code' => $readerCountryCode ],
			] ] )
			->disableOriginalClone()
			->disableArgumentCloning()
			->disallowMockingUnknownTypes()
			->getMock();

		$readerMock->method( 'country' )->with( $ip )->willReturn(
			$countryModelMock
		);

		$wikimediaEventsCountryCodeLookup = new WikimediaEventsCountryCodeLookup(
			$readerMock
		);
		$webRequest = new FauxRequest();
		$webRequest->setIP( $ip );
		$this->assertEquals(
			$expectedCountryCode,
			$wikimediaEventsCountryCodeLookup->getFromGeoIP( $webRequest )
		);
	}

	public static function provideGetCountryCodeTestCases(): array {
		return [
			'Country code comes from GeoIP2 Reader class' => [
				'DE',
				'DE',
				'127.0.0.1',
			],
			'Invalid IP' => [
				null,
				null,
				'foo',
			],
			'Return unknown instead of null if isoCode is null on country record' => [
				null,
				null,
				'127.0.0.1',
			]
		];
	}

}
