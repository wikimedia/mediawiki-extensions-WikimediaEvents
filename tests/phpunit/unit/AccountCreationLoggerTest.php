<?php

use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Extension\EventLogging\EventLogging;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;
use WikimediaEvents\AccountCreationLogger;

class AccountCreationLoggerTest extends MediaWikiUnitTestCase {

	/**
	 * @covers \WikimediaEvents\AccountCreationLogger::logLoginEvent
	 */
	public function testLogLoginEvent() {
		$mockUserIdentityUtils = $this->createMock( UserIdentityUtils::class );
		$mockSpecialPageFactory = $this->createMock( SpecialPageFactory::class );
		$mockEventLogging = $this->createMock( EventLogging::class );
		$accountCreationLogger = $this->getMockBuilder( AccountCreationLogger::class )
			->setConstructorArgs( [
				$mockUserIdentityUtils,
				$mockSpecialPageFactory
			] )
			->onlyMethods( [ 'logAuthEvent' ] )
			->getMock();
		$performerMock = $this->createMock( UserIdentity::class );
		$responseMock = $this->createMock( AuthenticationResponse::class );
		$responseMock->status = AuthenticationResponse::PASS;

		$performerMock->method( 'getId' )->willReturn( 1 );
		$performerMock->method( 'getName' )->willReturn( 'TestUser' );
		$accountCreationLogger->expects( $this->once() )
			->method( 'logAuthEvent' )
			->with(
				'mediawiki.accountcreation.login',
				'success',
				$performerMock,
				$responseMock
			);
		$accountCreationLogger->logLoginEvent( 'success', $performerMock, $responseMock );
	}

	/**
	 * @covers \WikimediaEvents\AccountCreationLogger::logAccountCreationEvent
	 */
	public function testLogAccountCreationEventSuccess() {
		$mockUserIdentityUtils = $this->createMock( UserIdentityUtils::class );
		$mockSpecialPageFactory = $this->createMock( SpecialPageFactory::class );
		$mockEventLogging = $this->createMock( EventLogging::class );

		$accountCreationLogger = $this->getMockBuilder( AccountCreationLogger::class )
			->setConstructorArgs( [
				$mockUserIdentityUtils,
				$mockSpecialPageFactory
			] )
			->onlyMethods( [ 'logAuthEvent' ] )
			->getMock();

		$performerMock = $this->createMock( UserIdentity::class );
		$responseMock = $this->createMock( AuthenticationResponse::class );
		$responseMock->status = AuthenticationResponse::PASS;
		$accountCreationLogger->expects( $this->once() )
			->method( 'logAuthEvent' )
			->with(
				'mediawiki.accountcreation.account_conversion',
				'success',
				$performerMock,
				$responseMock
			);

		$accountCreationLogger->logAccountCreationEvent( 'success', $performerMock, $responseMock );
	}
}
