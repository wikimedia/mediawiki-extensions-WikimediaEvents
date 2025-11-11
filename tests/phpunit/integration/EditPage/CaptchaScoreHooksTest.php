<?php

namespace WikimediaEvents\Tests\Integration\EditPage;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\hCaptcha\HCaptcha;
use MediaWiki\Extension\ConfirmEdit\Hooks;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Page\WikiPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\Title;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\EditPage\CaptchaScoreHooks;

/**
 * @covers \WikimediaEvents\EditPage\CaptchaScoreHooks
 * @group Database
 */
class CaptchaScoreHooksTest extends MediaWikiIntegrationTestCase {

	public static function provideShouldNotSubmitEvent(): array {
		return [
			'SimpleCaptcha instead of HCaptcha' => [
				[ 'edit' => [ 'trigger' => true, 'class' => 'SimpleCaptcha' ] ],
			],
			'Captcha trigger disabled' => [
				[ 'edit' => [ 'trigger' => false, 'class' => 'HCaptcha' ] ],
			],
		];
	}

	/**
	 * @dataProvider provideShouldNotSubmitEvent
	 */
	public function testPageSaveCompleteShouldNotSubmitEvent( array $captchaTriggers ) {
		$this->overrideConfigValue( 'CaptchaTriggers', $captchaTriggers );
		$services = $this->getServiceContainer();
		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->never() )->method( 'submit' );

		$user = $this->createMock( User::class );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getUserGroupManager(),
			$services->getCentralIdLookup()
		);

		$wikiPageMock = $this->createMock( WikiPage::class );
		$titleMock = $this->createMock( Title::class );
		$wikiPageMock->method( 'getTitle' )->willReturn( $titleMock );
		$revisionRecordMock = $this->createMock( RevisionRecord::class );
		$revisionRecordMock->method( 'getId' )->willReturn( 1 );

		$captchaScoreHooks->onPageSaveComplete(
			$wikiPageMock,
			$user,
			'',
			'',
			$revisionRecordMock,
			$this->createMock( EditResult::class )
		);
	}

	public function testPageSaveCompleteShouldNotSubmitEventForUserWithSkipCaptcha() {
		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [ 'trigger' => true, 'class' => 'HCaptcha' ] ]
		);
		$services = $this->getServiceContainer();
		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->never() )->method( 'submit' );

		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		/** @var HCaptcha $simpleCaptcha */
		$simpleCaptcha = Hooks::getInstance( CaptchaTriggers::EDIT );
		$simpleCaptcha->storeSessionScore( 'hCaptcha-score', 0.1, $user->getName() );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getUserGroupManager(),
			$services->getCentralIdLookup()
		);

		$wikiPageMock = $this->createMock( WikiPage::class );
		$titleMock = $this->createMock( Title::class );
		$wikiPageMock->method( 'getTitle' )->willReturn( $titleMock );
		$revisionRecordMock = $this->createMock( RevisionRecord::class );
		$revisionRecordMock->method( 'getId' )->willReturn( 1 );

		$captchaScoreHooks->onPageSaveComplete(
			$wikiPageMock,
			$user,
			'',
			'',
			$revisionRecordMock,
			$this->createMock( EditResult::class )
		);
	}

	public static function provideShouldSubmitEvent(): array {
		return [
			'With editing_session_id' => [
				'Foo',
				0.1,
				[ 'editingStatsId' => 123 ],
				1,
				true,
			],
			'Without editing_session_id' => [
				'Bar',
				0.5,
				[],
				2,
				false,
			],
			'With null risk score (defaults to -1)' => [
				'Baz',
				null,
				[],
				3,
				false,
			],
		];
	}

	/**
	 * @dataProvider provideShouldSubmitEvent
	 */
	public function testPageSaveCompleteWithHCaptcha(
		string $userName,
		?float $riskScore,
		array $requestParams,
		int $revisionId,
		bool $hasEditingSessionId
	) {
		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [
				'trigger' => true,
				'class' => 'HCaptcha',
			] ]
		);
		$services = $this->getServiceContainer();
		/** @var HCaptcha $simpleCaptcha */
		$simpleCaptcha = Hooks::getInstance( CaptchaTriggers::EDIT );
		$user = $this->createMock( User::class );
		$user->method( 'getName' )->willReturn( $userName );
		$user->method( 'isRegistered' )->willReturn( false );
		if ( $riskScore !== null ) {
			$simpleCaptcha->storeSessionScore( 'hCaptcha-score', $riskScore, $userName );
		}
		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$request = new FauxRequest( $requestParams, true );
		RequestContext::getMain()->setRequest( $request );

		$userFactoryMock = $this->createMock( UserFactory::class );
		$userFactoryMock->method( 'newFromUserIdentity' )->willReturn( $user );
		$userGroupManagerMock = $this->createMock( UserGroupManager::class );
		$userGroupManagerMock->method( 'getUserGroups' )->willReturn( [] );
		$centralIdLookupMock = $this->createMock( CentralIdLookup::class );
		$centralIdLookupMock->method( 'centralIdFromLocalUser' )->willReturn( 0 );

		$userEntitySerializer = new UserEntitySerializer(
			$userFactoryMock,
			$userGroupManagerMock,
			$centralIdLookupMock
		);
		$expectedPerformer = $userEntitySerializer->toArray( $user );

		$expectedEvent = [
			'$schema' => '/analytics/mediawiki/hcaptcha/risk_score/1.0.0',
			'action' => CaptchaTriggers::EDIT,
			'wiki_id' => WikiMap::getCurrentWikiId(),
			'identifier' => $revisionId,
			'identifier_type' => 'revision',
			'performer' => $expectedPerformer,
			'http' => [
				'method' => 'POST',
			],
			'risk_score' => $riskScore !== null ? $riskScore : -1.0,
			'mw_entry_point' => MW_ENTRY_POINT,
		];
		if ( $hasEditingSessionId ) {
			$expectedEvent['editing_session_id'] = (string)$requestParams['editingStatsId'];
		}

		$eventSubmitterMock->expects( $this->once() )->method( 'submit' )
			->with(
				'mediawiki.hcaptcha.risk_score',
				$expectedEvent
			);
		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$userFactoryMock,
			$userGroupManagerMock,
			$centralIdLookupMock
		);
		$wikiPageMock = $this->createMock( WikiPage::class );
		$titleMock = $this->createMock( Title::class );
		$wikiPageMock->method( 'getTitle' )->willReturn( $titleMock );
		$revisionRecordMock = $this->createMock( RevisionRecord::class );
		$revisionRecordMock->method( 'getId' )->willReturn( $revisionId );
		$captchaScoreHooks->onPageSaveComplete(
			$wikiPageMock,
			$user,
			'',
			'',
			$revisionRecordMock,
			$this->createMock( EditResult::class )
		);
	}

}
