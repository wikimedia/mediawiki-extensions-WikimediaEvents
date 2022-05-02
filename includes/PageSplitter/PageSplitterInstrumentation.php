<?php

namespace WikimediaEvents\PageSplitter;

use Wikimedia\Assert\Assert;

/**
 * Deterministic sampling and bucketing based on a page IDs.
 *
 * The caller takes care of turning a page ID into a deterministic hash with
 * uniform probability distribution (see PageRandomGenerate).
 *
 * Given an example page that is assigned 0.421 and 3 buckets (A, B, C), it works as follows:
 *
 * - The assigned float is scaled to cover the three buckets, in #scaledHash().
 *   0.421 * 3 = 1.263
 *
 * - Each whole number represents a bucket. This case we're in bucket B.
 *   A = 0.x, B = 1.x, C = 2.x
 *
 * - The fraction within each number represents the sampling, so if our sampling ratio
 *   is 0.5, than x.00 to x.50 would be sampled, and x.50 to x.99 would be unsampled.
 *   In this case we're 1.263 which is sampled, and in bucket B.
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
	 * @param array $buckets An optional array of bucket name strings, e.g., `[ 'control', 'treatment' ]`.
	 */
	public function __construct( float $samplingRatio, array $buckets ) {
		Assert::parameter(
			$samplingRatio >= 0 && $samplingRatio <= 1,
			'samplingRatio',
			'Sampling ratio must be in range [0, 1]'
		);
		$this->samplingRatio = $samplingRatio;
		$this->buckets = $buckets;
	}

	/**
	 * Whether given page is in the sample.
	 *
	 * Should be called before getBucket().
	 *
	 * @param float $pageHash
	 * @return bool True if sampled, false if unsampled.
	 */
	public function isSampled( float $pageHash ): bool {
		// Take the right of the decimal.
		$sample = fmod( $this->scaledHash( $pageHash ), 1 );
		return $sample < $this->samplingRatio;
	}

	/**
	 * Which bucket a given page is in.
	 *
	 * This does NOT imply sampling and should usually be called after isSampled().
	 *
	 * @param float $pageHash
	 * @return string|null Bucket name or null if buckets are unused.
	 */
	public function getBucket( float $pageHash ): ?string {
		if ( $this->buckets === [] ) {
			return null;
		}

		// Take the left of the decimal. Floor (truncate) the scaled number to
		// [0, count( $buckets ) - 1] for use as an index.
		$index = (int)$this->scaledHash( $pageHash );
		// For the case when scaledHash returns a float that rounds up to the next int,
		// check that the index is within bounds of the buckets array count.
		if ( $index >= count( $this->buckets ) ) {
			$index--;
		}
		return $this->buckets[ $index ];
	}

	/**
	 * @param float $pageHash
	 * @return float Integer component is the bucket, fractional component is the sample rate.
	 */
	private function scaledHash( float $pageHash ): float {
		return $pageHash * max( 1, count( $this->buckets ) );
	}

}
