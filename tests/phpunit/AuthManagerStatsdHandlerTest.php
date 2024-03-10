<?php

namespace WikimediaEvents\Tests;

use MediaWikiIntegrationTestCase;
use Wikimedia\Stats\Metrics\CounterMetric;
use Wikimedia\Stats\StatsFactory;
use WikimediaEvents\AuthManagerStatsdHandler;

/**
 * @covers \WikimediaEvents\AuthManagerStatsdHandler
 */
class AuthManagerStatsdHandlerTest extends MediaWikiIntegrationTestCase {
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
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'entrypoint' => 'web' ] ] ],
			'other channel' => [ [
				'channel' => 'captcha',
				'context' => [ 'event' => 'autocreate' ],
			], [ 'authmanager_event_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'entrypoint' => 'web' ] ] ],
			'wrong channel' => [ [
				'channel' => 'authentication',
				'context' => [ 'event' => 'autocreate' ],
			], null ],

			'simple' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate' ],
			], [ 'authmanager_event_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'entrypoint' => 'web' ] ] ],
			'type' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'eventType' => 'session' ],
			], [ 'authmanager_event_total',
			   [ 'event' => 'autocreate', 'subtype' => 'session', 'entrypoint' => 'web' ] ] ],
			'type fallback' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'type' => 'session' ],
			], [ 'authmanager_event_total',
			   [ 'event' => 'autocreate', 'subtype' => 'session', 'entrypoint' => 'web' ] ] ],
			'success' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => true ],
			], [ 'authmanager_success_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'entrypoint' => 'web' ] ] ],
			'failure' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => false ],
			], [ 'authmanager_error_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'reason' => 'n/a', 'entrypoint' => 'web' ] ] ],
			'success with status' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => true, 'status' => 'snafu' ],
			], [ 'authmanager_success_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'entrypoint' => 'web' ] ] ],
			'failure with status' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => false, 'status' => 'snafu' ],
			], [ 'authmanager_error_total',
			   [ 'event' => 'autocreate', 'subtype' => 'n/a', 'reason' => 'snafu', 'entrypoint' => 'web' ] ] ],
		];
	}
}
