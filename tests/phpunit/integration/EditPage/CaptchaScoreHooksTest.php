<?php

namespace WikimediaEvents\Tests\Integration\EditPage;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\hCaptcha\HCaptcha;
use MediaWiki\Extension\ConfirmEdit\Hooks;
use MediaWiki\Extension\EventLogging\MetricsPlatform\MetricsClientFactory;
use MediaWiki\Page\WikiPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use Wikimedia\MetricsPlatform\MetricsClient;
use WikimediaEvents\EditPage\CaptchaScoreHooks;

/**
 * @covers \WikimediaEvents\EditPage\CaptchaScoreHooks
 */
class CaptchaScoreHooksTest extends MediaWikiIntegrationTestCase {

	public function testPageSaveCompleteWithoutHCaptchaSet() {
		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [
				'trigger' => true,
				'class' => 'SimpleCaptcha',
			] ]
		);
		$metricsClientFactoryMock = $this->createMock( MetricsClientFactory::class );
		$metricsClientFactoryMock->expects( $this->never() )->method( 'newMetricsClient' );
		$captchaScoreHooks = new CaptchaScoreHooks( $metricsClientFactoryMock );
		$captchaScoreHooks->onPageSaveComplete(
			$this->createMock( WikiPage::class ),
			$this->createMock( User::class ),
			'',
			'',
			$this->createMock( RevisionRecord::class ),
			$this->createMock( EditResult::class )
		);
	}

	public function testPageSaveCompleteWithCaptchaTriggerFalse() {
		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [
				'trigger' => false,
				'class' => 'HCaptcha',
			] ]
		);
		$metricsClientFactoryMock = $this->createMock( MetricsClientFactory::class );
		$metricsClientFactoryMock->expects( $this->never() )->method( 'newMetricsClient' );
		$captchaScoreHooks = new CaptchaScoreHooks( $metricsClientFactoryMock );
		$captchaScoreHooks->onPageSaveComplete(
			$this->createMock( WikiPage::class ),
			$this->createMock( User::class ),
			'',
			'',
			$this->createMock( RevisionRecord::class ),
			$this->createMock( EditResult::class )
		);
	}

	public function testPageSaveCompleteWithHCaptcha() {
		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [
				'trigger' => true,
				'class' => 'HCaptcha',
			] ]
		);
		/** @var HCaptcha $simpleCaptcha */
		$simpleCaptcha = Hooks::getInstance( CaptchaTriggers::EDIT );
		$userIdentity = $this->createMock( UserIdentityValue::class );
		$userIdentity->method( 'getName' )->willReturn( 'Foo' );
		$simpleCaptcha->storeSessionScore( 'hCaptcha-score', 0.1, 'Foo' );
		$metricsClientFactoryMock = $this->createMock( MetricsClientFactory::class );
		$metricsClientMock = $this->createMock( MetricsClient::class );
		$metricsClientFactoryMock->expects( $this->once() )->method( 'newMetricsClient' )
			->willReturn( $metricsClientMock );
		RequestContext::getMain()->setRequest( new FauxRequest( [ 'editingStatsId' => 123 ] ) );
		$metricsClientMock->expects( $this->once() )->method( 'submitInteraction' )
			->with(
				'mediawiki.hcaptcha.risk_score',
				'/analytics/mediawiki/hcaptcha/risk_score/1.0.0',
				'edit',
				[
					'identifier' => 1,
					'identifier_type' => 'revision',
					'risk_score' => 0.1,
					'mw_entry_point' => MW_ENTRY_POINT,
					'editing_session_id' => '123'
				]
			);
		$captchaScoreHooks = new CaptchaScoreHooks( $metricsClientFactoryMock );
		$revisionRecordMock = $this->createMock( RevisionRecord::class );
		$revisionRecordMock->method( 'getId' )->willReturn( 1 );
		$captchaScoreHooks->onPageSaveComplete(
			$this->createMock( WikiPage::class ),
			$userIdentity,
			'',
			'',
			$revisionRecordMock,
			$this->createMock( EditResult::class )
		);
	}

}
