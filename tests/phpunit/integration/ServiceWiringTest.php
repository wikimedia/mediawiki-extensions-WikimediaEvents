<?php

namespace WikimediaEvents\Tests\Integration;

use MediaWikiIntegrationTestCase;

/**
 * Tests ServiceWiring.php by ensuring that the call to the
 * service does not result in an error.
 *
 * @coversNothing PHPUnit does not support covering annotations for files
 * @group WikimediaEvents
 * @group Database
 */
class ServiceWiringTest extends MediaWikiIntegrationTestCase {
	/**
	 * @dataProvider provideService
	 */
	public function testService( string $name ) {
		$this->getServiceContainer()->get( $name );
		$this->addToAssertionCount( 1 );
	}

	public static function provideService() {
		$wiring = require __DIR__ . '/../../../includes/ServiceWiring.php';
		foreach ( $wiring as $name => $_ ) {
			yield $name => [ $name ];
		}
	}
}
