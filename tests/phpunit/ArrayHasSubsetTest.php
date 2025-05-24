<?php

namespace WikimediaEvents\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @covers \WikimediaEvents\Tests\ArrayHasSubset
 */
class ArrayHasSubsetTest extends TestCase {

	/**
	 * @dataProvider provideMatch
	 */
	public function testMatch( array $constraint, array $target, bool $expectedResult ) {
		$constraint = new ArrayHasSubset( $constraint );
		$this->assertSame( $expectedResult, $constraint->evaluate( $target, '', true ) );
	}

	public static function provideMatch() {
		return [
			[ [], [], true ],
			[ [], [ 1 ], true ],
			[ [ 1 ], [], false ],
			[ [ 1 ], [ 1 ], true ],
			[ [ 'a' => 1, 'b' => 2 ], [ 'a' => 1, 'b' => 2, 'c' => 3 ], true ],
			[ [ 'a' => 1, 'b' => 2 ], [ 'c' => 3, 'b' => 2, 'a' => 1 ], true ],
			[ [ 'a' => 1, 'b' => 2 ], [ 'a' => 1, 'c' => 3 ], false ],
			[ [ 'a' => 1, 'b' => 2 ], [ 'b' => 2, 'a' => 1, 'c' => 3 ], true ],
			[ [ 'a' => 1, 'b' => 2 ], [ 'a' => 1, 'b' => 100, 'c' => 3 ], false ],
			[ [ 'a' => 1, 'b' => 2 ], [ 'a' => 1, 'b' => '2', 'c' => 3 ], false ],
		];
	}

}
