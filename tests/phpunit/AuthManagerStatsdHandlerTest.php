<?php

namespace WikimediaEvents\Tests;

use MediaWiki\Extension\CentralAuth\SharedDomainUtils;
use MediaWikiIntegrationTestCase;
use Wikimedia\Stats\Metrics\CounterMetric;
use Wikimedia\Stats\StatsFactory;
use WikimediaEvents\AuthManagerStatsdHandler;

/**
 * @covers \WikimediaEvents\AuthManagerStatsdHandler
 */
class AuthManagerStatsdHandlerTest extends MediaWikiIntegrationTestCase {

	public function testSul3Label() {
		$this->markTestSkippedIfExtensionNotLoaded( 'CentralAuth' );

		$counter = $this->createMock( CounterMetric::class );
		$counter->method( 'setLabel' )->willReturnSelf();
		$counter->method( 'copyToStatsdAt' )->willReturnSelf();

		$stats = $this->createMock( StatsFactory::class );
		$this->setService( 'StatsFactory', $stats );

		$utils = $this->createMock( SharedDomainUtils::class );
		$utils->expects( $this->once() )
			->method( 'isSul3Enabled' )
			->willReturn( true );
		$this->setService( 'CentralAuth.SharedDomainUtils', $utils );

		$stats->method( 'withComponent' )->willReturnSelf();
		$stats->method( 'getCounter' )->willReturn( $counter );

		$expectedLabels = [
			'entrypoint' => 'web',
			'event' => 'autocreate',
			'subtype' => 'n/a',
			'sul3' => 'enabled'
		];

		$handler = new AuthManagerStatsdHandler();
		$stats->expects( $this->once() )->method( 'getCounter' )->with( 'authmanager_event_total' );
		$setLabelMock = $counter->expects( $this->exactly( 4 ) )->method( 'setLabel' );
		$setLabelMock->willReturnCallback( function ( $key, $value ) use ( $expectedLabels ){
			$this->assertSame( $expectedLabels[$key], $value, sprintf( "unexpected setLabel(%s, %s) call",
				var_export( $key, true ), var_export( $value, true ) ) );
		} );
		$counter->expects( $this->once() )->method( 'increment' );
		$handler->handle( [
			'channel' => 'authevents',
			'context' => [ 'event' => 'autocreate' ],
		] );
	}

	/**
	 * @dataProvider provideHandle
	 */
	public function testHandle( $record, $expectedMetric ) {
		$counter = $this->createMock( CounterMetric::class );
		$counter->method( 'setLabel' )->willReturnSelf();
		$counter->method( 'copyToStatsdAt' )->willReturnSelf();

		$stats = $this->createMock( StatsFactory::class );
		$this->setService( 'StatsFactory', $stats );
		$stats->method( 'withComponent' )->willReturnSelf();
		$stats->method( 'getCounter' )->willReturn( $counter );

		$handler = new AuthManagerStatsdHandler();

		if ( $expectedMetric === null ) {
			$counter->expects( $this->never() )->method( 'increment' );
		} else {
			[ $metricName, $metricLabels ] = $expectedMetric;
			$stats->expects( $this->once() )->method( 'getCounter' )->with( $metricName );
			// Check $metricLabels matches setLabel() arguments and call count
			$setLabelMock = $counter->expects( $this->exactly( count( $metricLabels ) ) )->method( 'setLabel' );
			$setLabelMock->willReturnCallback( function ( $key, $value ) use ( $metricLabels ) {
				$this->assertEquals( $metricLabels[$key], $value, sprintf( "unexpected setLabel(%s, %s) call",
					var_export( $key, true ), var_export( $value, true ) ) );
			} );
			$counter->expects( $this->once() )->method( 'increment' );
		}

		$handler->handle( $record );
	}

	public static function provideHandle() {
		return [
			'no event' => [ [
				'channel' => 'authevents',
				'context' => [ 'foo' => 'bar' ],
			], null ],
			'wrong type' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'type' => [ 'oops' ] ],
			], null ],

			'right channel' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate' ],
			], [ 'authmanager_event_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'entrypoint' => 'web', 'sul3' => 'disabled' ] ] ],
			'other channel' => [ [
				'channel' => 'captcha',
				'context' => [ 'event' => 'autocreate' ],
			], [ 'authmanager_event_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'entrypoint' => 'web', 'sul3' => 'disabled' ] ] ],
			'wrong channel' => [ [
				'channel' => 'authentication',
				'context' => [ 'event' => 'autocreate' ],
			], null ],

			'simple' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate' ],
			], [ 'authmanager_event_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'entrypoint' => 'web', 'sul3' => 'disabled' ] ] ],
			'type' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'eventType' => 'session', 'sul3' => 'disabled' ],
			], [ 'authmanager_event_total',
			   [ 'event' => 'autocreate', 'subtype' => 'session', 'entrypoint' => 'web', 'sul3' => 'disabled' ] ] ],
			'type fallback' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'type' => 'session' ],
			], [ 'authmanager_event_total',
			   [ 'event' => 'autocreate', 'subtype' => 'session', 'entrypoint' => 'web', 'sul3' => 'disabled' ] ] ],
			'success' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => true ],
			], [ 'authmanager_success_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'entrypoint' => 'web', 'sul3' => 'disabled' ] ] ],
			'failure' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => false ],
			], [ 'authmanager_error_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'reason' => 'n/a',
				   'entrypoint' => 'web', 'sul3' => 'disabled' ] ] ],
			'success with status' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => true, 'status' => 'snafu' ],
			], [ 'authmanager_success_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'entrypoint' => 'web', 'sul3' => 'disabled' ] ] ],
			'failure with status' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => false, 'status' => 'snafu' ],
			], [ 'authmanager_error_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'reason' => 'snafu',
				   'entrypoint' => 'web', 'sul3' => 'disabled' ] ] ],
			'pass account type when present' => [ [
					'channel' => 'authevents',
					'context' => [ 'event' => 'autocreate', 'accountType' => 'temp' ],
				], [ 'authmanager_event_total',
					[ 'event' => 'autocreate', 'subtype' => 'n/a', 'entrypoint' => 'web',
						'accountType' => 'temp', 'sul3' => 'disabled' ]
			] ],
		];
	}
}
