<?php

namespace WikimediaEvents\XLab;

use MediaWiki\Extension\MetricsPlatform\XLab\ExperimentManager;
use MediaWiki\Hook\BeforePageDisplayHook;

/**
 * A simple experiment-specific instrument that sends a "page-visited" event if the current user is
 * enrolled in the "synth-aa-test-mw-php" experiment.
 *
 * See https://phabricator.wikimedia.org/T397143 for more context
 */
class PageVisitInstrument implements BeforePageDisplayHook {

	/** @var string */
	private const EXPERIMENT_NAME = 'synth-aa-test-mw-php';
	private ?ExperimentManager $experimentManager;

	/**
	 * @param ExperimentManager|null $experimentManager
	 */
	public function __construct( ?ExperimentManager $experimentManager = null ) {
		$this->experimentManager = $experimentManager;
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		// Is MetricsPlatform loaded?
		if ( !$this->experimentManager ) {
			return;
		}

		$experiment = $this->experimentManager->getExperiment( self::EXPERIMENT_NAME );
		$experiment->send(
			'page-visited',
			[
				'instrument_name' => 'PageVisit'
			]
		);
	}
}
