<?php

namespace WikimediaEvents\PageSplitter;

use Wikimedia\Assert\Assert;
use Wikimedia\Assert\ParameterAssertionException;

/**
 * Distributes a page across buckets with uniform probability and monotonous assignments. The
 * sampling ratio is independent of bucketing and may be used conventionally for controlling bucket
 * populations or also to perform staged rollouts. See unit tests for example usages.
 *
 * @license GPL-2.0-or-later
 */
class PageSplitterInstrumentation {
	/**
	 * @var float
	 */
	private $samplingRatio;

	/**
	 * @var array
	 */
	private $buckets;

	/**
	 * @param float $samplingRatio The sampling ratio, [0, 1].
	 * @param array $buckets An array of bucket name strings. E.g., [ 'Control', 'Treatment' ].
	 * Possibly empty if bucketing is unused.
	 * Pages are bucketed in [0, .5) for control and [.5, 1) for treatment. If a page is sampled and
	 * bucketed in treatment, it will contain the new schema changes. Otherwise, it will have no changes.
	 *
	 * @throws ParameterAssertionException
	 */
	public function __construct( float $samplingRatio, array $buckets ) {
		Assert::parameter(
			$samplingRatio >= 0 && $samplingRatio <= 1,
			'samplingRatio',
			'Sampling ratio of "' . $samplingRatio . '; expected to be in the domain of [0, 1].'
		);
		$this->samplingRatio = $samplingRatio;
		$this->buckets = $buckets;
	}

	/**
	 * Usually called prior to getBucket(). May be called without getBucket() for staged rollouts:
	 *
	 * if ( $tester->isSampled( $pageRandom ) ) {
	 * // Perform split test or just execute new risky code.
	 * } else {
	 * // Execute old stable code.
	 * }
	 *
	 * @param float $pageIdRandom A randomized float with hashed page.page_id.
	 * @return bool True if sampled, false if unsampled.
	 */
	public function isSampled( float $pageIdRandom ): bool {
		// Take the right of the decimal.
		$sample = fmod( $this->scaledRandom( $pageIdRandom ), 1 );
		return $sample < $this->samplingRatio;
	}

	/**
	 * A call to this function usually follows a call to isSampled(). E.g.:
	 * $bucket = $tester->isSampled( $pageRandom ) ? $tester->getBucket( $pageRandom ) : null;
	 * All inputs are bucketed regardless of sampling unless buckets is empty.
	 * @param float $pageIdRandom A randomized float with page.page_id as seed.
	 * @return string|null Bucket name or null if buckets is empty.
	 * The result does not imply sampling.
	 */
	public function getBucket( float $pageIdRandom ) {
		if ( $this->buckets === [] ) {
			return null;
		}

		// Take the left of the decimal. Floor (truncate) the scaled random number to
		// [0, count( $buckets ) - 1] for use as an index.
		$index = (int)$this->scaledRandom( $pageIdRandom );
		return $this->buckets[ $index ];
	}

	/**
	 * @param float $pageIdRandom A randomized float with hashed page.page_id.
	 *
	 * @return float A monotonic random number from [0, max( 1, count( buckets ) )). The integer
	 *   component is the bucket when buckets is nonempty; the fractional component is the
	 *   sampled rate.
	 */
	private function scaledRandom( float $pageIdRandom ): float {
		return $pageIdRandom * max( 1, count( $this->buckets ) );
	}

}
