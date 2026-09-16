<?php

namespace WikimediaEvents\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\BlockUtils;
use WikimediaEvents\WikimediaEventsCountryCodeLookup;

/**
 * @covers \WikimediaEvents\BlockUtils
 * @group Database
 */
class BlockUtilsTest extends MediaWikiIntegrationTestCase {

	/**
	 * @param string|null $geoIpCookie Value for the GeoIP cookie, or null to leave it unset
	 */
	private function setUpRequest( ?string $geoIpCookie = null ): void {
		$request = new FauxRequest( [], true );
		if ( $geoIpCookie !== null ) {
			$request->setCookies( [ 'GeoIP' => $geoIpCookie ], '' );
		}
		RequestContext::getMain()->setRequest( $request );
	}

	/**
	 * @param string $expiry Block expiry, in a format accepted by the block store
	 */
	private function getBlockedUser( string $expiry ): User {
		$user = $this->getTestUser()->getUser();
		$this->getServiceContainer()->getDatabaseBlockStore()->insertBlockWithParams( [
			'targetUser' => $user,
			'by' => $this->getTestSysop()->getUser(),
			'expiry' => $expiry,
		] );
		// Clear the cached block so the new one is picked up.
		$user->clearInstanceCache();
		return $user;
	}

	/**
	 * Captures the event passed to EventLogging, or asserts that none is submitted.
	 *
	 * @param array|null &$captured Populated with [ stream name, event ]
	 */
	private function expectSubmittedEvent( ?array &$captured, bool $expectSubmit = true ): void {
		$eventSubmitter = $this->createMock( EventSubmitter::class );
		if ( !$expectSubmit ) {
			$eventSubmitter->expects( $this->never() )->method( 'submit' );
		} else {
			$eventSubmitter->expects( $this->once() )
				->method( 'submit' )
				->willReturnCallback( static function ( $stream, $event ) use ( &$captured ) {
					$captured = [ $stream, $event ];
				} );
		}
		$this->setService( 'EventLogging.EventSubmitter', $eventSubmitter );
	}

	public function testLogBlockedEditAttemptWithoutBlockDoesNotSubmit(): void {
		$this->setUpRequest();
		$captured = null;
		$this->expectSubmittedEvent( $captured, false );

		BlockUtils::logBlockedEditAttempt(
			$this->getTestUser()->getUser(),
			Title::makeTitle( NS_MAIN, 'Test page' ),
			'test interface',
			'test platform'
		);
	}

	/** @dataProvider provideLogBlockedEditAttemptSubmitsEvent */
	public function testLogBlockedEditAttemptSubmitsEvent( string $blockExpiry ): void {
		$this->setUpRequest( 'FR:Paris' );
		$title = $this->getExistingTestPage()->getTitle();
		$captured = null;
		$this->expectSubmittedEvent( $captured );

		// If the GeoIP cookie is present, then the country code lookup service should not be called
		$this->setService(
			'WikimediaEventsCountryCodeLookup',
			$this->createNoOpMock( WikimediaEventsCountryCodeLookup::class )
		);

		$blockedUser = $this->getBlockedUser( $blockExpiry );
		BlockUtils::logBlockedEditAttempt(
			$blockedUser,
			$title,
			'test interface',
			'test platform'
		);

		[ $stream, $event ] = $captured;
		$this->assertSame( 'mediawiki.editattempt_block', $stream );
		$this->assertArrayEquals(
			[
				'$schema' => '/analytics/mediawiki/editattemptsblocked/1.3.0',
				'block_id' => json_encode( $blockedUser->getBlock()->getIdentifier() ),
				'block_type' => 'user',
				'block_expiry' => $blockExpiry,
				'block_scope' => 'local',
				'platform' => 'test platform',
				'interface' => 'test interface',
				'country_code' => 'FR',
				'database' => $this->getServiceContainer()->getMainConfig()->get( MainConfigNames::DBname ),
				'page_id' => $title->getId(),
				'page_namespace' => $title->getNamespace(),
				'rev_id' => $title->getLatestRevID(),
				'performer' => [
					'user_id' => $blockedUser->getId(),
					'user_edit_count' => $blockedUser->getEditCount() ?: 0,
				],
			],
			$event,
			false,
			true,
			'Event data should be as expected'
		);
	}

	public static function provideLogBlockedEditAttemptSubmitsEvent(): array {
		return [
			'blocked user with an expiry' => [ 'blockExpiry' => '2099-01-01T00:00:00Z' ],
			'blocked user with infinite expiry' => [ 'blockExpiry' => 'infinity' ],
		];
	}

	public function testLogBlockedEditAttemptWithoutGeoIpCookie(): void {
		$this->setUpRequest();
		// Without the cookie the code falls through to the lookup service, which needs the
		// GeoIP database. Stub it so the fallback path is exercised without that dependency.
		$lookup = $this->createMock( WikimediaEventsCountryCodeLookup::class );
		$lookup->expects( $this->once() )
			->method( 'getFromGeoIP' )
			->willReturn( 'DE' );
		$this->setService( 'WikimediaEventsCountryCodeLookup', $lookup );
		$captured = null;
		$this->expectSubmittedEvent( $captured );

		BlockUtils::logBlockedEditAttempt(
			$this->getBlockedUser( 'infinity' ),
			Title::makeTitle( NS_MAIN, 'Test page' ),
			'test interface',
			'test platform'
		);

		$this->assertSame( 'DE', $captured[1]['country_code'] );
	}
}
