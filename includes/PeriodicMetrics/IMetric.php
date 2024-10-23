<?php

namespace WikimediaEvents\PeriodicMetrics;

/**
 * Represents a metric that can be calculated at any time.
 * Copied, with modification, from GrowthExperiments includes/PeriodicMetrics/IMetric.php
 */
interface IMetric {
	/**
	 * Calculate the value of the metric.
	 *
	 * @return int
	 */
	public function calculate(): int;

	/**
	 * Gets the Prometheus labels to use for the calculated value.
	 *
	 * @return array
	 */
	public function getLabels(): array;

	/**
	 * Gets the name of the metric.
	 *
	 * @return string
	 */
	public function getName(): string;
}
