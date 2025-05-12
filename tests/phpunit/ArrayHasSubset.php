<?php

namespace WikimediaEvents\Tests;

use PHPUnit\Framework\Constraint\Constraint;

/**
 * True if the array given as constructor parameter is a subset of the actual value,
 * with strict equality.
 */
class ArrayHasSubset extends Constraint {

	private array $subset;

	public function __construct( array $subset ) {
		$this->subset = $subset;
	}

	/** @inheritDoc */
	protected function matches( $other ): bool {
		if ( is_array( $other ) ) {
			foreach ( $this->subset as $key => $value ) {
				if ( !array_key_exists( $key, $other ) || $other[$key] !== $value ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/** @inheritDoc */
	public function toString(): string {
		return 'superset of ' . $this->exporter()->export( $this->subset );
	}

	/** @inheritDoc */
	protected function failureDescription( $other ): string {
		return $this->exporter()->export( $other ) . ' is ' . $this->toString();
	}

}
