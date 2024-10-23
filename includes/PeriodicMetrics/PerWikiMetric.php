<?php

namespace WikimediaEvents\PeriodicMetrics;

use WikiMap;

/**
 * A base class used for metrics which are grouped per-wiki.
 */
abstract class PerWikiMetric implements IMetric {

	/** @inheritDoc */
	public function getLabels(): array {
		return [ 'wiki' => WikiMap::getCurrentWikiId() ];
	}

	/** @inheritDoc */
	abstract public function calculate(): int;

	/** @inheritDoc */
	abstract public function getName(): string;
}
