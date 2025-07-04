<?php
namespace WikimediaEvents\CreateAccount;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\EventLogging\MetricsPlatform\MetricsClientFactory;

/**
 * Wrapper class for emitting server-side interaction events to the Special:CreateAccount
 * Metrics Platform instrument.
 */
class CreateAccountInstrumentationClient {
	private MetricsClientFactory $metricsClientFactory;

	public function __construct( MetricsClientFactory $metricsClientFactory ) {
		$this->metricsClientFactory = $metricsClientFactory;
	}

	/**
	 * Emit an interaction event to the Special:CreateAccount Metrics Platform instrument.
	 * @param IContextSource $context
	 * @param string $action The action name to use for the interaction
	 * @param array $interactionData Interaction data for the event
	 */
	public function submitInteraction(
		IContextSource $context,
		string $action,
		array $interactionData
	): void {
		$client = $this->metricsClientFactory->newMetricsClient( $context );

		$client->submitInteraction(
			'mediawiki.product_metrics.special_create_account',
			'/analytics/product_metrics/web/base/1.3.0',
			$action,
			$interactionData
		);
	}
}
