<?php

namespace WikimediaEvents\Tests;

use IBufferingStatsdDataFactory;
use MediaWikiIntegrationTestCase;
use Status;
use StatusValue;
use WikimediaEvents\AuthManagerStatsdHandler;

/**
 * @covers \WikimediaEvents\AuthManagerStatsdHandler
 */
class AuthManagerStatsdHandlerTest extends MediaWikiIntegrationTestCase {
	/**
	 * @dataProvider provideHandle
	 */
	public function testHandle( $record, $expectedKey ) {
		$stats = $this->createMock( IBufferingStatsdDataFactory::class );
		$this->setService( 'StatsdDataFactory', $stats );
		$handler = $this->getMockBuilder( AuthManagerStatsdHandler::class )
			->onlyMethods( [ 'getEntryPoint' ] )->getMock();
		$handler->method( 'getEntryPoint' )->willReturn( null );

		if ( $expectedKey === null ) {
			$stats->expects( $this->never() )->method( 'increment' );
		} else {
			$stats->expects( $this->once() )->method( 'increment' )->with( $expectedKey );
		}

		$handler->handle( $record );
	}

	public function provideHandle() {
		$multiStatus = Status::newGood();
		$multiStatus->fatal( 'foo' );
		$multiStatus->fatal( 'bar' );

		return [
			'no event' => [ [
				'channel' => 'authevents',
				'context' => [ 'foo' => 'bar' ],
			], null ],
			'wrong type' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'type' => Status::newGood() ],
			], null ],

			'right channel' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate' ],
			], 'authmanager.autocreate' ],
			'other channel' => [ [
				'channel' => 'captcha',
				'context' => [ 'event' => 'autocreate' ],
			], 'authmanager.autocreate' ],
			'wrong channel' => [ [
				'channel' => 'authentication',
				'context' => [ 'event' => 'autocreate' ],
			], null ],

			'simple' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate' ],
			], 'authmanager.autocreate' ],
			'type' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'eventType' => 'session' ],
			], 'authmanager.autocreate.session' ],
			'type fallback' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'type' => 'session' ],
			], 'authmanager.autocreate.session' ],
			'success' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => true ],
			], 'authmanager.autocreate.success' ],
			'failure' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => false ],
			], 'authmanager.autocreate.failure' ],
			'success with status' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => true, 'status' => 'snafu' ],
			], 'authmanager.autocreate.success' ],
			'failure with status' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => false, 'status' => 'snafu' ],
			], 'authmanager.autocreate.failure.snafu' ],

			'Status, good' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'status' => Status::newGood() ],
			], 'authmanager.autocreate.success' ],
			'Status, bad' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'status' => Status::newFatal( 'snafu' ) ],
			], 'authmanager.autocreate.failure.snafu' ],
			'Status, multiple' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'status' => $multiStatus ],
			], 'authmanager.autocreate.failure.foo' ],
			'StatusValue' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'status' => StatusValue::newGood() ],
			], 'authmanager.autocreate.success' ],
		];
	}
}
