<?php

namespace WikimediaEvents\Tests;

use MediaWikiUnitTestCase;
use Wikimedia\Assert\ParameterAssertionException;
use WikimediaEvents\PageSplitter\PageSplitterInstrumentation;

/**
 * @covers \WikimediaEvents\PageSplitter\PageSplitterInstrumentation
 */
class PageSplitterInstrumentationTest extends MediaWikiUnitTestCase {
	/**
	 * @dataProvider providerConstructInvalidStates
	 */
	public function testConstructInvalidStates( $samplingRatio, array $buckets ) {
		$this->expectException( ParameterAssertionException::class );
		$errorMessage = 'Bad value for parameter samplingRatio: Sampling ratio of "' . $samplingRatio;
		$errorMessage .= '; expected to be in the domain of [0, 1].';
		$this->expectExceptionMessage( $errorMessage );
		new PageSplitterInstrumentation( $samplingRatio, $buckets );
	}

	public function providerConstructInvalidStates() {
		return [
			'Out of range: negative integer' => [ -1, [] ],
			'Out of range: negative float' => [ -0.1, [] ],
			'Out of range: positive float' => [ 1.1, [] ],
			'Out of range: positive integer' => [ 2, [] ],
		];
	}

	/**
	 * @dataProvider providerConstructValidStates
	 */
	public function testConstructValidStates( $samplingRatio, array $buckets ) {
		new PageSplitterInstrumentation( $samplingRatio, $buckets );
		// Parameters are accepted without exceptions.
		$this->addToAssertionCount( 1 );
	}

	public function providerConstructValidStates() {
		return [
			'Defaults' => [ 0, [] ],
			'No sampling, A/B test' => [ 0, [ 'control', 'treatment' ] ],
			'50% sampling, no buckets' => [ 0.5, [] ],
			'100% sampling, no buckets' => [ 1, [] ],
			'100% sampling, A/B test' => [ 1, [ 'control', 'treatment' ] ],
			'10% sampling, split test' => [ 0.1, [ 'control', 'a', 'b', 'c' ] ]
		];
	}

	/**
	 * @dataProvider providerIsSample
	 */
	public function testIsSampled( $expected, $samplingRatio, array $buckets, $pageRandom ) {
		$subject = new PageSplitterInstrumentation( $samplingRatio, $buckets );
		$this->assertEquals( $expected, $subject->isSampled( $pageRandom ) );
	}

	public function providerIsSample() {
		return [
			'scaledRandom: 0, bucket: 0, sample: 0' => [ false, 0, [], 0 ],
			'scaledRandom: 0.5, bucket: 0, sample: 0' => [ false, 0, [], 0.5 ],
			'scaledRandom: 0, bucket: , sample: 0' => [ false, 0, [ 'control', 'treatment' ], 0 ],
			'scaledRandom: 0.98, bucket: 0, sample: 0.98' => [ false, 0, [ 'control', 'treatment' ], 0.49 ],
			'scaledRandom: 1.98; bucket: 1, sample: 0.98' => [ false, 0, [ 'control', 'treatment' ], 0.99 ],
			'scaledRandom1: 0, bucket: 0, sample: 0' => [ true, 0.5, [], 0 ],
			'scaledRandom: 0.5, bucket: 0, sample: 0.5' => [ false, 0.5, [], 0.5 ],
			'scaledRandom: 0.99, bucket: 0, sample: 0.99' => [ false, 0.5, [], 0.99 ],
			'scaledRandom2: 0, bucket: 0, sample: 0' => [ true, 1, [], 0 ],
			'scaledRandom1: 0.5, bucket: 0, sample: 0.5' => [ true, 1, [], 0.5 ],
			'scaledRandom1: 0.99, bucket: 0, sample: 0.99' => [ true, 1, [], 0.99 ],
			'scaledRandom3: 0, bucket: 0, sample: 0' => [ true, 1, [ 'a', 'b', 'c' ], 0 ],
			'scaledRandom2: 0.99, bucket: 0, sample: 0.99' => [ true, 1, [ 'a', 'b', 'c' ], 0.33 ],
			'scaledRandom: 2.97, bucket: 2, sample: 0.97' => [ true, 1, [ 'a', 'b', 'c' ], 0.99 ],
			'scaledRandom4: 0, bucket: 0, sample: 0' => [ true, 0.1, [ 'control', 'a', 'b', 'c' ], 0 ],
			'scaledRandom: 0.096, bucket: 0, sample: 0.096' => [ true, 0.1, [ 'control', 'a', 'b', 'c' ], 0.024 ],
			'scaledRandom: 0.10, bucket: 0, sample: 0.10' => [ false, 0.1, [ 'control', 'a', 'b', 'c' ], 0.025 ],
			'scaledRandom: 3.96, bucket: 3, sample: 0.96' => [ false, 0.1, [ 'control', 'a', 'b', 'c' ], 0.99 ]
		];
	}

	/**
	 * @dataProvider providerGetBucket
	 */
	public function testGetBucket( $expected, $samplingRatio, array $buckets, $pageRandom ) {
		$subject = new PageSplitterInstrumentation( $samplingRatio, $buckets );
		$this->assertEquals( $expected, $subject->getBucket( $pageRandom ) );
	}

	public function providerGetBucket() {
		return [
			'scaledRandom: 0, bucket: 0, sample: 0' => [ null, 0, [], 0 ],
			'scaledRandom: 0, bucket: 0, sample: 0.99' => [ null, 0, [], 0.99 ],
			'scaledRandom1: 0, bucket: 0, sample: 0' => [ 'enabled', 0, [ 'enabled' ], 0 ],
			'scaledRandom1: 0, bucket: 0, sample: 0.99' => [ 'enabled', 0, [ 'enabled' ], 0.99 ],
			'scaledRandom2: 0, bucket: 0, sample: 0' => [ 'control', 0, [ 'control', 'treatment' ], 0 ],
			'scaledRandom: 0.98, bucket: 0, sample: 0.98' => [ 'control', 0, [ 'control', 'treatment' ], 0.49 ],
			'scaledRandom: 1, bucket: 1, sample: 0' => [ 'treatment', 0, [ 'control', 'treatment' ], 0.5 ],
			'scaledRandom: 1.98, bucket: 1, sample: 0.98' => [ 'treatment', 0, [ 'control', 'treatment' ], 0.99 ],
			'scaledRandom3: 0, bucket: 0, sample: 0' => [ null, 0.5, [], 0 ],
			'scaledRandom4: 0, bucket: 0, sample: 0' => [ null, 1, [], 0 ],
			'scaledRandom5: 0, bucket: 0, sample: 0' => [ 'a', 1, [ 'a', 'b', 'c' ], 0 ],
			'scaledRandom: 0.99, bucket: 0, sample: 0.99' => [ 'a', 1, [ 'a', 'b', 'c' ], 0.33 ],
			'scaledRandom: 1.02, bucket: 1, sample: 0.02' => [ 'b', 1, [ 'a', 'b', 'c' ], 0.34 ],
			'scaledRandom1: 1.98, bucket: 1, sample: 0.98' => [ 'b', 1, [ 'a', 'b', 'c' ], 0.66 ],
			'scaledRandom: 2.01, bucket: 2, sample: 0.01' => [ 'c', 1, [ 'a', 'b', 'c' ], 0.67 ],
			'scaledRandom: 2.97, bucket: 2, sample: 0.97' => [ 'c', 1, [ 'a', 'b', 'c' ], 0.99 ],
			'scaledRandom6: 0, bucket: 0, sample: 0' => [ 'control', 0.1, [ 'control', 'a', 'b', 'c' ], 0 ],
			'scaledRandom: 0.096, bucket: 0, sample: 0.096' => [ 'control', 0.1, [ 'control', 'a', 'b', 'c' ], 0.024 ],
			'scaledRandom: 0.1, bucket: 0, sample: 0.1' => [ 'control', 0.1, [ 'control', 'a', 'b', 'c' ], 0.025 ],
			'scaledRandom: 0.96, bucket: 0, sample: 0.96' => [ 'control', 0.1, [ 'control', 'a', 'b', 'c' ], 0.24 ],
			'scaledRandom1: 1, bucket: 1, sample: 0' => [ 'a', 0.1, [ 'control', 'a', 'b', 'c' ], 0.25 ],
			'scaledRandom: 3.96, bucket: 3, sample: 0.96' => [ 'c', 0.1, [ 'control', 'a', 'b', 'c' ], 0.99 ]
		];
	}

	public function testScenarioAb1() {
		// "control" / "treatment" A/B test with 1% sampling.
		$sampling = 0.01;
		$buckets = [ /*A*/ 'control', /*B*/ 'treatment' ];
		$subject = new PageSplitterInstrumentation( $sampling, $buckets );

		// Supply page_random at different values.
		$sampled = [ 0.000, 0.001, 0.002, 0.003, 0.004, 0.500, 0.501, 0.502, 0.503, 0.504 ];
		$unsampled1 = [ 0.005, 0.008, 0.009, 0.010, 0.011, 0.012, 0.013, 0.015, 0.019, 0.100, 0.200, 0.490 ];
		$unsampled2 = [ 0.505, 0.508, 0.509, 0.510, 0.800, 0.999 ];
		$unsampled = array_merge( $unsampled1, $unsampled2 );

		// [0, .005) and [.5, .505) are sampled
		foreach ( $sampled as $value ) {
			$this->assertTrue( $subject->isSampled( $value ) );
		}
		// [.005, .5) and [.505, 1) are unsampled.
		foreach ( $unsampled as $value ) {
			$this->assertFalse( $subject->isSampled( $value ) );
		}

		// Supply page_random at different values.
		$control1 = [ 0.000, 0.001, 0.002, 0.003, 0.004, 0.005, 0.008, 0.009 ];
		$control2 = [ 0.010, 0.011, 0.012, 0.013, 0.015, 0.018, 0.019, 0.100, 0.200, 0.490 ];
		$control = array_merge( $control1, $control2 );
		$treatment = [ 0.500, 0.501, 0.502, 0.503, 0.504, 0.505, 0.508, 0.509, 0.510, 0.800, 0.999 ];

		// [0, .5) are "control".
		foreach ( $control as $value ) {
			$this->assertEquals( 'control', $subject->getBucket( $value ) );
		}

		// [.5, 1) are "treatment".
		foreach ( $treatment as $value ) {
			$this->assertEquals( 'treatment', $subject->getBucket( $value ) );
		}
		// Thus, pages sampled at 1% in "treatment" may be found for page_random in [.5, .505).
	}

	public function testScenarioAb10() {
		// A/B test with 10% sampling.
		$subject = new PageSplitterInstrumentation( 0.1, [ 'a', 'b' ] );
		$this->assertTrue( $subject->isSampled( 0.00 ) );
		$this->assertEquals( 'a', $subject->getBucket( 0.00 ) );
		$this->assertTrue( $subject->isSampled( 0.01 ) );
		$this->assertEquals( 'a', $subject->getBucket( 0.01 ) );
		$this->assertFalse( $subject->isSampled( 0.05 ) );
		$this->assertEquals( 'a', $subject->getBucket( 0.05 ) );
		$this->assertFalse( $subject->isSampled( 0.1 ) );
		$this->assertEquals( 'a', $subject->getBucket( 0.1 ) );
		$this->assertTrue( $subject->isSampled( 0.5 ) );
		$this->assertEquals( 'b', $subject->getBucket( 0.5 ) );
		$this->assertTrue( $subject->isSampled( 0.51 ) );
		$this->assertEquals( 'b', $subject->getBucket( 0.51 ) );
		$this->assertFalse( $subject->isSampled( 0.9 ) );
		$this->assertEquals( 'b', $subject->getBucket( 0.9 ) );
	}

	public function testScenarioRollout1() {
		// Rollout with 1% sampling.
		$subject = new PageSplitterInstrumentation( 0.01, [] );
		$this->assertTrue( $subject->isSampled( 0.00 ) );
		$this->assertNull( $subject->getBucket( 0.00 ) );
		$this->assertTrue( $subject->isSampled( 0.005 ) );
		$this->assertNull( $subject->getBucket( 0.005 ) );
		$this->assertFalse( $subject->isSampled( 0.01 ) );
		$this->assertNull( $subject->getBucket( 0.01 ) );
		$this->assertFalse( $subject->isSampled( 0.05 ) );
		$this->assertNull( $subject->getBucket( 0.05 ) );
		$this->assertFalse( $subject->isSampled( 0.1 ) );
		$this->assertNull( $subject->getBucket( 0.1 ) );
		$this->assertFalse( $subject->isSampled( 0.5 ) );
		$this->assertNull( $subject->getBucket( 0.5 ) );
		$this->assertFalse( $subject->isSampled( 0.9 ) );
		$this->assertNull( $subject->getBucket( 0.9 ) );
	}

	public function testScenarioRollout100() {
		// Drop buckets from [ 'control', 'treatment' ], a 50 / 50 split, to [ 'treatment' ] and
		// increase sampling to 100%.
		$subject = new PageSplitterInstrumentation( 1, [ 'treatment' ] );
		$this->assertTrue( $subject->isSampled( 0.0 ) );
		$this->assertEquals( 'treatment', $subject->getBucket( 0.0 ) );
		$this->assertTrue( $subject->isSampled( 0.9 ) );
		$this->assertEquals( 'treatment', $subject->getBucket( 0.9 ) );
	}

	/**
	 * This is probabilistic and may cause a false positive with a very low probability.
	 * Increase the iterations or tolerance if this occurs.
	 */
	public function testScenarioSplit50() {
		// A/B/C split test with 50% sampling.
		$subject = new PageSplitterInstrumentation( 0.5, [ 'a', 'b', 'c' ] );

		$sampled = 0;
		$buckets = [ 'a' => 0, 'b' => 0, 'c' => 0 ];
		$iterations = 100000;
		for ( $i = 0; $i < $iterations; ++$i ) {
			$pageRandom = wfRandom();
			$sampled += $subject->isSampled( $pageRandom ) ? 1 : 0;
			$buckets[ $subject->getBucket( $pageRandom ) ]++;
		}

		$this->assertEqualsWithDelta( 0.50, $sampled / $iterations, 0.01 );
		$this->assertEqualsWithDelta( 0.33, $buckets['a'] / $iterations, 0.01 );
		$this->assertEqualsWithDelta( 0.33, $buckets['b'] / $iterations, 0.01 );
		$this->assertEqualsWithDelta( 0.33, $buckets['c'] / $iterations, 0.01 );
	}

}
