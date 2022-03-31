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
	 * @dataProvider provideConstructorInvalidRatio
	 */
	public function testConstructorInvalidRatio( $samplingRatio, array $buckets ) {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage( 'Sampling ratio must be in range [0, 1]' );
		new PageSplitterInstrumentation( $samplingRatio, $buckets );
	}

	public static function provideConstructorInvalidRatio() {
		return [
			'Out of range: negative integer' => [ -1, [] ],
			'Out of range: negative float' => [ -0.1, [] ],
			'Out of range: positive float' => [ 1.1, [] ],
			'Out of range: positive integer' => [ 2, [] ],
		];
	}

	/**
	 * @dataProvider provideConstructValidArgs
	 */
	public function testConstructorValidArgs( $samplingRatio, array $buckets ) {
		new PageSplitterInstrumentation( $samplingRatio, $buckets );
		// Parameters are accepted without exceptions.
		$this->addToAssertionCount( 1 );
	}

	public static function provideConstructValidArgs() {
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
	 * @dataProvider provideSampledAndBucket
	 */
	public function testSampledAndBucket(
		float $ratio, array $buckets, float $page, bool $sampled, ?string $bucket
	) {
		$subject = new PageSplitterInstrumentation( $ratio, $buckets );
		$this->assertEquals( $sampled, $subject->isSampled( $page ) );

		$subject = new PageSplitterInstrumentation( $ratio, $buckets );
		$this->assertEquals( $bucket, $subject->getBucket( $page ) );
	}

	public static function provideSampledAndBucket() {
		// No sampling
		yield [
			'ratio' => 0.0, 'buckets' => [],
			'page' => 0.00, 'sampled' => false, 'bucket' => null
		];
		yield [
			'ratio' => 0.0, 'buckets' => [],
			'page' => 0.50, 'sampled' => false, 'bucket' => null
		];
		yield [
			'ratio' => 0.0, 'buckets' => [ 'control', 'treatment' ],
			'page' => 0.00, 'sampled' => false, 'bucket' => 'control'
		];
		yield [
			'ratio' => 0.0, 'buckets' => [ 'control', 'treatment' ],
			'page' => 0.49, 'sampled' => false, 'bucket' => 'control'
		];
		yield [
			'ratio' => 0.0, 'buckets' => [ 'control', 'treatment' ],
			'page' => 0.99 , 'sampled' => false, 'bucket' => 'treatment'
		];

		// 50% sampling ratio
		yield [
			'ratio' => 0.5, 'buckets' => [],
			'page' => 0.00, 'sampled' => true, 'bucket' => null
		];
		yield [
			'ratio' => 0.5, 'buckets' => [],
			'page' => 0.50, 'sampled' => false, 'bucket' => null
		];
		yield [
			'ratio' => 0.5, 'buckets' => [],
			'page' => 0.99, 'sampled' => false, 'bucket' => null
		];

		// 100% sampling ratio
		yield [
			'ratio' => 1.0, 'buckets' => [],
			'page' => 0.00, 'sampled' => true, 'bucket' => null
		];
		yield [ 'ratio' => 1.0, 'buckets' => [],
			'page' => 0.50, 'sampled' => true, 'bucket' => null
		];
		yield [
			'ratio' => 1.0, 'buckets' => [],
			'page' => 0.99, 'sampled' => true, 'bucket' => null
		];
		yield [
			'ratio' => 1.0, 'buckets' => [ 'a', 'b', 'c' ],
			'page' => 0.00, 'sampled' => true, 'bucket' => 'a'
		];
		yield [
			'ratio' => 1.0, 'buckets' => [ 'a', 'b', 'c' ],
			'page' => 0.33, 'sampled' => true, 'bucket' => 'a'
		];
		yield [
			'ratio' => 1.0, 'buckets' => [ 'a', 'b', 'c' ],
			'page' => 0.99, 'sampled' => true, 'bucket' => 'c'
		];

		// 10% sampling
		yield [
			'ratio' => 0.1, 'buckets' => [ 'control', 'a', 'b', 'c' ],
			'page' => 0.00, 'sampled' => true, 'bucket' => 'control'
		];
		yield [
			'ratio' => 0.1, 'buckets' => [ 'control', 'a', 'b', 'c' ],
			'page' => 0.024, 'sampled' => true, 'bucket' => 'control'
		];
		yield [
			'ratio' => 0.1, 'buckets' => [ 'control', 'a', 'b', 'c' ],
			'page' => 0.025, 'sampled' => false, 'bucket' => 'control'
		];
		yield [
			'ratio' => 0.1, 'buckets' => [ 'control', 'a', 'b', 'c' ],
			'page' => 0.99, 'sampled' => false, 'bucket' => 'c'
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
