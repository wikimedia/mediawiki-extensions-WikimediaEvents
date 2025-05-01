<?php

namespace WikimediaEvents\Tests\Unit;

use GeoIp2\Database\Reader;
use GeoIp2\Model\Country;
use MediaWiki\Request\WebRequest;
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
		$webRequest = $this->createMock( WebRequest::class );
		$webRequest->method( 'getCookie' )
			->with( 'GeoIP' )->willReturn( $geoIpCookie );
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

		$countryRecordMock = $this->createMock( \GeoIp2\Record\Country::class );
		$countryRecordMock->method( '__get' )->with( 'isoCode' )->willReturn( $readerCountryCode );
		$countryModelMock = $this->createMock( Country::class );
		$countryModelMock->method( '__get' )->with( 'country' )->willReturn(
			$countryRecordMock
		);
		$readerMock->method( 'country' )->with( $ip )->willReturn(
			$countryModelMock
		);
		$wikimediaEventsCountryCodeLookup = new WikimediaEventsCountryCodeLookup(
			$readerMock
		);
		$webRequest = $this->createMock( WebRequest::class );
		$webRequest->method( 'getIp' )->willReturn( $ip );
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
