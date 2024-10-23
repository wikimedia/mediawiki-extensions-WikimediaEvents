<?php

namespace WikimediaEvents\Tests\Integration\Services;

use MediaWiki\Context\RequestContext;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWikiIntegrationTestCase;
use Skin;
use Wikimedia\TestingAccessWrapper;
use WikimediaEvents\Services\WikimediaEventsRequestDetailsLookup;

/**
 * @covers \WikimediaEvents\Services\WikimediaEventsRequestDetailsLookup
 */
class WikimediaEventsRequestDetailsLookupTest extends MediaWikiIntegrationTestCase {

	use TempUserTestTrait;

	private function getMockObjectUnderTest( string $mwEntryPointConstantValue ): WikimediaEventsRequestDetailsLookup {
		// Mock ::getMWEntryPointConstant to return the value we want for the test.
		$objectUnderTest = $this->getMockBuilder( WikimediaEventsRequestDetailsLookup::class )
			->onlyMethods( [ 'getMWEntryPointConstant' ] )
			->getMock();
		$objectUnderTest->method( 'getMWEntryPointConstant' )
			->willReturn( $mwEntryPointConstantValue );
		return $objectUnderTest;
	}

	/** @dataProvider provideGetEntryPoint */
	public function testGetEntryPoint( $mwEntryPointConstantValue, $expectedReturnValue ) {
		$this->assertSame(
			$expectedReturnValue,
			$this->getMockObjectUnderTest( $mwEntryPointConstantValue )->getEntryPoint()
		);
	}

	public static function provideGetEntryPoint() {
		return [
			'MW_ENTRY_POINT is index' => [ 'index', 'index' ],
			'MW_ENTRY_POINT is api' => [ 'api', 'api' ],
			'MW_ENTRY_POINT is rest' => [ 'rest', 'api' ],
			'MW_ENTRY_POINT is thumb' => [ 'thumb', 'other' ],
		];
	}

	/** @dataProvider provideGetPlatformDetails */
	public function testGetPlatformDetails(
		$userAgent, $useMinervaSkin, $mwEntryPointConstantValue, $expectedReturnArray
	) {
		// Set up the request to have the values for this test
		$requestContext = RequestContext::getMain();
		$requestContext->getRequest()->setHeader( 'User-agent', $userAgent );
		if ( $useMinervaSkin ) {
			$skin = $this->createMock( Skin::class );
			$skin->method( 'getSkinName' )->willReturn( 'minerva' );
			$requestContext->setSkin( $skin );
		}
		// Call the method under test and verify that it returns the expected array
		$this->assertArrayEquals(
			$expectedReturnArray,
			$this->getMockObjectUnderTest( $mwEntryPointConstantValue )->getPlatformDetails(),
			false,
			true
		);
	}

	public static function provideGetPlatformDetails() {
		return [
			'Commons app' => [ 'Commons/Blah', false, 'api', [ 'platform' => 'commons', 'isMobile' => '1' ] ],
			'iOS Wikipedia app' => [ 'WikipediaApp/iOS', false, 'api', [ 'platform' => 'ios', 'isMobile' => '1' ] ],
			'Android app' => [ 'WikipediaApp/Android', false, 'api', [ 'platform' => 'android', 'isMobile' => '1' ] ],
			'Unknown' => [ 'Unknown', false, 'unknown', [ 'platform' => 'unknown', 'isMobile' => '0' ] ],
			'Web using Minerva' => [ 'ignored', true, 'index', [ 'platform' => 'web', 'isMobile' => '1' ] ],
			'Web using Vector' => [ 'ignored', false, 'index', [ 'platform' => 'web', 'isMobile' => '0' ] ],
		];
	}

	public function testGetMWEntryPointConstant() {
		$objectUnderTest = TestingAccessWrapper::newFromObject( new WikimediaEventsRequestDetailsLookup() );
		$this->assertSame( MW_ENTRY_POINT, $objectUnderTest->getMWEntryPointConstant() );
	}
}
