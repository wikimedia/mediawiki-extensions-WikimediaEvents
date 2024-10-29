<?php

namespace WikimediaEvents\Tests;

use MediaWiki\Extension\CentralAuth\SharedDomainUtils;
use MediaWiki\WikiMap\WikiMap;
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
			'wiki' => WikiMap::getCurrentWikiId(),
			'entrypoint' => 'web',
			'event' => 'autocreate',
			'subtype' => 'n/a',
			'sul3' => 'enabled',
			'accountType' => 'n/a'
		];

		$handler = new AuthManagerStatsdHandler();
		$stats->expects( $this->once() )->method( 'getCounter' )->with( 'authmanager_event_total' );
		$setLabelMock = $counter->expects( $this->exactly( 6 ) )->method( 'setLabel' );
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
			$metricLabels['wiki'] = WikiMap::getCurrentWikiId();
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
		$defaultLabelSet = [
			'subtype' => 'n/a',
			'entrypoint' => 'web',
			'sul3' => 'disabled',
			'accountType' => 'n/a',
			'event' => 'autocreate'
		];

		yield 'no event' => [ [
				'channel' => 'authevents',
				'context' => [ 'foo' => 'bar' ],
			], null ];

		yield 'wrong type' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'type' => [ 'oops' ] ],
			], null ];

		yield 'right channel' => [
			[
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate' ],
			], [
				'authmanager_event_total',
				$defaultLabelSet,
			] ];

		yield 'other channel' => [ [
				'channel' => 'captcha',
				'context' => [ 'event' => 'autocreate' ],
			], [
				'authmanager_event_total',
				$defaultLabelSet,
			]
		];

		yield 'wrong channel' => [ [
				'channel' => 'authentication',
				'context' => [ 'event' => 'autocreate' ],
			], null
		];

		yield 'simple' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate' ],
			], [
				'authmanager_event_total',
				 $defaultLabelSet,
			] ];

		yield 'type' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'eventType' => 'session', 'sul3' => 'disabled' ],
			], [
				'authmanager_event_total',
				array_merge( $defaultLabelSet, [ 'subtype' => 'session' ] ),
			] ];

		yield 'type fallback' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'type' => 'session' ],
			], [
				'authmanager_event_total',
				array_merge( $defaultLabelSet, [ 'subtype' => 'session' ] ),
			] ];

		yield 'success' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => true ],
			], [
				'authmanager_success_total',
				$defaultLabelSet,
			] ];

		yield 'failure' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => false ],
			], [
				'authmanager_error_total',
				array_merge( $defaultLabelSet, [ 'reason' => 'n/a' ] ),
			] ];

		yield 'success with status' => [ [
				'channel' => 'authevents',
				'context' => [ 'event' => 'autocreate', 'successful' => true, 'status' => 'snafu' ],
			], [
				'authmanager_success_total',
				$defaultLabelSet,
		] ];

		yield 'failure with status' => [ [
				'channel' => 'authevents',
				'context' => [
					'event' => 'autocreate',
					'successful' => false,
					'status' => 'snafu',
					'accountType' => 'n/a'
				],
			], [ 'authmanager_error_total',
				array_merge( $defaultLabelSet, [ 'reason' => 'snafu' ] ),
		] ];

		yield 'pass account type when present' => [ [
					'channel' => 'authevents',
					'context' => [ 'event' => 'autocreate', 'accountType' => 'temp' ],
				], [
					'authmanager_event_total',
					array_merge( $defaultLabelSet, [ 'accountType' => 'temp' ] ),
			] ];
	}
}
