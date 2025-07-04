<?php
namespace WikimediaEvents\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\EventLogging\MetricsPlatform\MetricsClientFactory;
use MediaWikiUnitTestCase;
use Wikimedia\MetricsPlatform\MetricsClient;
use WikimediaEvents\CreateAccount\CreateAccountInstrumentationClient;

/**
 * @covers \WikimediaEvents\CreateAccount\CreateAccountInstrumentationClient
 */
class CreateAccountInstrumentationClientTest extends MediaWikiUnitTestCase {
	public function testShouldSubmitInteraction(): void {
		$context = $this->createMock( IContextSource::class );
		$metricsClient = $this->createMock( MetricsClient::class );
		$metricsClient->expects( $this->once() )
			->method( 'submitInteraction' )
			->with(
				'mediawiki.product_metrics.special_create_account',
				'/analytics/product_metrics/web/base/1.3.0',
				'foo',
				[ 'action_context' => 'test' ]
			);

		$metricsClientFactory = $this->createMock( MetricsClientFactory::class );
		$metricsClientFactory->method( 'newMetricsClient' )
			->with( $context )
			->willReturn( $metricsClient );

		$client = new CreateAccountInstrumentationClient( $metricsClientFactory );

		$client->submitInteraction( $context, 'foo', [ 'action_context' => 'test' ] );
	}
}
