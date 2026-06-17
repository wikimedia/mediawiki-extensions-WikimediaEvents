<?php

namespace WikimediaEvents\Tests\Integration\EditPage;

use MediaWiki\Api\ApiMessage;
use MediaWiki\Context\RequestContext;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Extension\ConfirmEdit\AbuseFilter\CaptchaConsequence;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\hCaptcha\HCaptcha;
use MediaWiki\Extension\ConfirmEdit\Services\CaptchaFactory;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Page\WikiPage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Status\Status;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;
use WikimediaEvents\EditPage\CaptchaScoreHooks;

/**
 * @covers \WikimediaEvents\EditPage\CaptchaScoreHooks
 * @group Database
 */
class CaptchaScoreHooksTest extends MediaWikiIntegrationTestCase {

	protected function tearDown(): void {
		// Reset the CaptchaFactory cache so per-test captcha state doesn't leak.
		$services = $this->getServiceContainer();
		if ( $services->hasService( 'ConfirmEditCaptchaFactory' ) ) {
			$services->get( 'ConfirmEditCaptchaFactory' )->unsetGlobalInstancesForTests();
		}
		parent::tearDown();
	}

	public function testWhenConfirmEditNotLoaded() {
		$mockExtensionRegistry = $this->createMock( ExtensionRegistry::class );
		$mockExtensionRegistry->method( 'isLoaded' )
			->with( 'ConfirmEdit' )
			->willReturn( false );

		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->never() )->method( 'submit' );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$this->getServiceContainer()->getUserFactory(),
			$mockExtensionRegistry,
			$this->getServiceContainer()->get( 'EventBus.UserEntitySerializer' ),
			null
		);

		// Should return immediately if ConfirmEdit is not loaded, so we can pass anything here that is deemed valid
		// by PHP.
		$captchaScoreHooks->onPageSaveComplete(
			$this->createMock( WikiPage::class ),
			UserIdentityValue::newAnonymous( '1.2.3.4' ),
			'',
			'',
			$this->createMock( RevisionRecord::class ),
			$this->createMock( EditResult::class )
		);
	}

	public static function provideShouldNotSubmitEvent(): array {
		return [
			'SimpleCaptcha instead of HCaptcha' => [
				[ 'edit' => [ 'trigger' => true, 'class' => 'SimpleCaptcha' ] ],
			],
		];
	}

	/**
	 * @dataProvider provideShouldNotSubmitEvent
	 */
	public function testPageSaveCompleteShouldNotSubmitEvent( array $captchaTriggers ) {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );

		$this->overrideConfigValue( 'CaptchaTriggers', $captchaTriggers );
		$services = $this->getServiceContainer();
		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->never() )->method( 'submit' );

		$user = $this->createMock( User::class );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getExtensionRegistry(),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$this->getServiceContainer()->get( 'ConfirmEditCaptchaFactory' )
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
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );

		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [ 'trigger' => true, 'class' => 'HCaptcha' ] ]
		);
		$services = $this->getServiceContainer();
		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->never() )->method( 'submit' );

		// Ensure the user has the required rights to skip captchas
		$this->setGroupPermissions( 'sysop', 'skipcaptcha', true );
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		/** @var CaptchaFactory $captchaFactory */
		$captchaFactory = $this->getServiceContainer()->get( 'ConfirmEditCaptchaFactory' );
		/** @var HCaptcha $simpleCaptcha */
		$simpleCaptcha = $captchaFactory->getGlobalInstance( CaptchaTriggers::EDIT );
		$simpleCaptcha->storeSessionScore( 'hCaptcha-score', 0.1, $user->getName() );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getExtensionRegistry(),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$captchaFactory
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
				false,
			],
			'Without editing_session_id' => [
				'Bar',
				0.5,
				[],
				2,
				false,
				false,
			],
			'With null risk score (defaults to -1)' => [
				'Baz',
				null,
				[],
				3,
				false,
				false,
			],
			'Null edit' => [
				'Qux',
				0.2,
				[],
				4,
				false,
				true,
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
		bool $hasEditingSessionId,
		bool $isNullEdit
	) {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );

		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [
				'trigger' => true,
				'class' => 'HCaptcha',
			] ]
		);
		$services = $this->getServiceContainer();
		/** @var CaptchaFactory $captchaFactory */
		$captchaFactory = $this->getServiceContainer()->get( 'ConfirmEditCaptchaFactory' );
		/** @var HCaptcha $simpleCaptcha */
		$simpleCaptcha = $captchaFactory->getGlobalInstance( CaptchaTriggers::EDIT );
		$user = $this->createMock( User::class );
		$user->method( 'getName' )->willReturn( $userName );
		$user->method( 'isRegistered' )->willReturn( false );
		if ( $riskScore !== null ) {
			$simpleCaptcha->storeSessionScore( 'hCaptcha-score', $riskScore, $userName );
		}
		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$request = new FauxRequest( $requestParams, true );
		RequestContext::getMain()->setRequest( $request );

		$userEntitySerializer = $services->get( 'EventBus.UserEntitySerializer' );
		$expectedPerformer = $userEntitySerializer->toArray( $user );

		$expectedEvent = $this->buildEventPayload( [
			'action' => $isNullEdit ? 'null_edit' : CaptchaTriggers::EDIT,
			'identifier' => $revisionId,
			'identifier_type' => 'revision',
			'performer' => $expectedPerformer,
			'risk_score' => $riskScore !== null ? $riskScore : -1.0,
		] );
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
			$services->getUserFactory(),
			$services->getExtensionRegistry(),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$captchaFactory
		);
		$wikiPageMock = $this->createMock( WikiPage::class );
		$titleMock = $this->createMock( Title::class );
		$wikiPageMock->method( 'getTitle' )->willReturn( $titleMock );
		$revisionRecordMock = $this->createMock( RevisionRecord::class );
		$revisionRecordMock->method( 'getId' )->willReturn( $revisionId );
		$editResultMock = $this->createMock( EditResult::class );
		$editResultMock->method( 'isNullEdit' )->willReturn( $isNullEdit );
		$captchaScoreHooks->onPageSaveComplete(
			$wikiPageMock,
			$user,
			'',
			'',
			$revisionRecordMock,
			$editResultMock
		);
	}

	/** A solved captcha (triggersCaptcha() false, T426056) must still be logged. */
	public function testPageSaveCompleteSubmitsEventWhenCaptchaAlreadySolved() {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );

		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [ 'trigger' => true, 'class' => 'HCaptcha' ] ]
		);
		$services = $this->getServiceContainer();
		/** @var CaptchaFactory $captchaFactory */
		$captchaFactory = $services->get( 'ConfirmEditCaptchaFactory' );
		/** @var HCaptcha $hCaptcha */
		$hCaptcha = $captchaFactory->getGlobalInstance( CaptchaTriggers::EDIT );

		$user = $this->createMock( User::class );
		$user->method( 'getName' )->willReturn( 'Solver' );
		$user->method( 'isRegistered' )->willReturn( false );
		$hCaptcha->storeSessionScore( 'hCaptcha-score', 0.42, 'Solver' );

		// Simulate a solved captcha (T426056).
		TestingAccessWrapper::newFromObject( $hCaptcha )->setCaptchaSolved( true );
		$this->assertFalse(
			$hCaptcha->triggersCaptcha( CaptchaTriggers::EDIT ),
			'triggersCaptcha() should return false once the captcha is solved'
		);

		RequestContext::getMain()->setRequest( new FauxRequest( [], true ) );
		$userEntitySerializer = $services->get( 'EventBus.UserEntitySerializer' );
		$expectedEvent = $this->buildEventPayload( [
			'action' => CaptchaTriggers::EDIT,
			'identifier' => 5,
			'identifier_type' => 'revision',
			'performer' => $userEntitySerializer->toArray( $user ),
			'risk_score' => 0.42,
		] );

		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->once() )->method( 'submit' )
			->with( 'mediawiki.hcaptcha.risk_score', $expectedEvent );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getExtensionRegistry(),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$captchaFactory
		);
		$wikiPageMock = $this->createMock( WikiPage::class );
		$titleMock = $this->createMock( Title::class );
		$wikiPageMock->method( 'getTitle' )->willReturn( $titleMock );
		$revisionRecordMock = $this->createMock( RevisionRecord::class );
		$revisionRecordMock->method( 'getId' )->willReturn( 5 );
		$editResultMock = $this->createMock( EditResult::class );
		$editResultMock->method( 'isNullEdit' )->willReturn( false );

		$captchaScoreHooks->onPageSaveComplete(
			$wikiPageMock,
			$user,
			'',
			'',
			$revisionRecordMock,
			$editResultMock
		);
	}

	/**
	 * @dataProvider provideAttemptSaveAfterEvents
	 */
	public function testOnEditPageAttemptSaveAfterEvents( array $params ): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );
		// ConfirmEdit's CaptchaConsequence extends from Consequence, which is
		// provided by AbuseFilter: if the latter is not present, trying to use
		// the class constants from CaptchaConsequence here results in an error.
		$this->markTestSkippedIfExtensionNotLoaded( 'Abuse Filter' );

		[
			'status' => $status,
			'expectedRevisionId' => $expectedRevisionId,
			'expectedLogType' => $expectedLogType,
			'expectedAFApiMessageDetails' => $expectedAFApiMessageDetails,
			'captchaTriggers' => $captchaTriggers,
			'shouldSubmit' => $shouldSubmit,
			'abuseFilterIdSessionData' => $abuseFilterIdSessionData,
			'isBrowser' => $isBrowser,
		] = $params;

		$this->overrideConfigValue( 'CaptchaTriggers', $captchaTriggers );

		$services = $this->getServiceContainer();
		$user = $this->getTestUser()->getUser();

		/** @var CaptchaFactory $captchaFactory */
		$captchaFactory = $this->getServiceContainer()->get( 'ConfirmEditCaptchaFactory' );
		$captchaInstance = $captchaFactory->getGlobalInstance( CaptchaTriggers::EDIT );
		if ( $captchaInstance instanceof HCaptcha ) {
			$captchaInstance->storeSessionScore(
				'hCaptcha-score',
				0.37,
				$user->getName()
			);
		}

		$request = new FauxRequest(
			[
				'editingStatsId' => 'session-42',
				'parentRevId' => $expectedRevisionId
			],
			true
		);

		if ( $isBrowser !== null ) {
			$request->setHeader( 'x-is-browser', $isBrowser );
		}

		RequestContext::getMain()->setRequest( $request );

		if ( $abuseFilterIdSessionData > 0 ) {
			$request->getSession()->set(
				CaptchaConsequence::FILTER_ID_SESSION_KEY,
				$abuseFilterIdSessionData
			);
		} else {
			$request->getSession()->remove(
				CaptchaConsequence::FILTER_ID_SESSION_KEY
			);
		}

		$eventSubmitterMock = $this->createMock( EventSubmitter::class );

		if ( $shouldSubmit ) {
			$userEntitySerializer = $services->get( 'EventBus.UserEntitySerializer' );
			$expectedEvent = $this->buildEventPayload( [
				'action' => 'failed_edit',
				'identifier' => $expectedRevisionId,
				'identifier_type' => 'latest_revision',
				'performer' => $userEntitySerializer->toArray( $user ),
				'risk_score' => 0.37,
				'editing_session_id' => 'session-42',
				'log_type' => $expectedLogType,
			] );
			$expectedEvent = array_merge(
				$expectedEvent,
				$expectedAFApiMessageDetails ?? []
			);

			$eventSubmitterMock->expects( $this->once() )
				->method( 'submit' )
				->with( 'mediawiki.hcaptcha.risk_score', $expectedEvent );
		} else {
			$eventSubmitterMock->expects( $this->never() )->method( 'submit' );
		}

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getExtensionRegistry(),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$captchaFactory
		);

		$contextMock = $this->createMock( RequestContext::class );
		$contextMock->method( 'getUser' )->willReturn( $user );

		$editPageMock = $this->createMock( EditPage::class );
		$editPageMock->method( 'getContext' )->willReturn( $contextMock );
		$titleMock = $this->createMock( Title::class );
		$titleMock->method( 'getPrefixedText' )->willReturn( 'Talk:Failure' );
		$editPageMock->method( 'getTitle' )->willReturn( $titleMock );
		$revisionMock = $this->createMock( RevisionRecord::class );
		$revisionMock->method( 'getId' )->willReturn( 101 );
		$editPageMock->method( 'getExpectedParentRevision' )->willReturn( $revisionMock );

		$captchaScoreHooks->onEditPage__attemptSave_after(
			$editPageMock,
			$status,
			[]
		);
	}

	public static function provideAttemptSaveAfterEvents(): array {
		$hCaptchaTrigger = [
			'trigger' => true,
			'class' => 'HCaptcha'
		];

		return [
			// Scenarios where hCaptcha is not triggered
			//
			'With a different type of captcha' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
					[ 'abusefilter' => [ 'id' => 123 ] ]
				) ),
				'expectedRevisionId' => 0,
				'expectedLogType' => '',
				'expectedAFApiMessageDetails' => [],
				'captchaTriggers' => [
					'edit' => array_merge(
						$hCaptchaTrigger,
						[ 'class' => 'FancyCaptcha' ]
					),
				],
				'shouldSubmit' => false,
				'abuseFilterIdSessionData' => null,
				'isBrowser' => null,
			] ],
			// Scenarios passing the Abuse Filter ID in the status object
			//
			'hCaptcha failure, valid Abuse Filter ID in the status object (integer)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
					[ 'abusefilter' => [ 'id' => 123 ] ]
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					'log_type' => 'abuse_filter',
					'abuse_filter_id' => 123
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => null,
				'isBrowser' => null,
			] ],
			'hCaptcha failure, valid Abuse Filter ID in the status object (numeric string)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
					[ 'abusefilter' => [ 'id' => '123' ] ]
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					'log_type' => 'abuse_filter',
					'abuse_filter_id' => 123
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => null,
				'isBrowser' => null,
			] ],
			'hCaptcha failure, invalid Abuse Filter ID in the status object (non-numeric string)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
					[ 'abusefilter' => [ 'id' => 'foobar' ] ]
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					// Log type is other due to the inability
					// to determine the filter ID
					'log_type' => 'other',
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => null,
				'isBrowser' => null,
			] ],
			'hCaptcha failure, invalid Abuse Filter ID in the status object (negative string)' => [ [
				// Negative values are forbidden by the schema
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
					[ 'abusefilter' => [ 'id' => '-1' ] ]
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					// Log type is other due to the inability
					// to determine the filter ID
					'log_type' => 'other',
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => null,
				'isBrowser' => null,
			] ],
			'hCaptcha failure, invalid Abuse Filter ID in the status object (negative integer)' => [ [
				// Negative values are forbidden by the schema
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
					[ 'abusefilter' => [ 'id' => -1 ] ]
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					// Log type is other due to the inability
					// to determine the filter ID
					'log_type' => 'other',
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => null,
				'isBrowser' => null,
			] ],
			'hCaptcha failure, invalid Abuse Filter ID in the status object (zero, integer)' => [ [
				// Zero is forbidden by the schema
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
					[ 'abusefilter' => [ 'id' => 0 ] ]
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					// Log type is other due to the inability
					// to determine the filter ID
					'log_type' => 'other',
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => null,
				'isBrowser' => null,
			] ],
			'hCaptcha failure, invalid Abuse Filter ID in the status object (zero, string)' => [ [
				// Zero is forbidden by the schema
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
					[ 'abusefilter' => [ 'id' => '0' ] ]
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					// Log type is other due to the inability
					// to determine the filter ID
					'log_type' => 'other',
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => null,
				'isBrowser' => null,
			] ],

			// Scenarios passing the Abuse Filter ID through the user session
			//
			'hCaptcha failure, valid AbuseFilter ID in the session object (integer)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'hcaptcha-force-show-captcha-edit',
					'hcaptcha-force-show-captcha-edit',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					'log_type' => 'abuse_filter',
					'abuse_filter_id' => 123
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => 123,
				'isBrowser' => null,
			] ],
			'hCaptcha failure, valid AbuseFilter ID in the session object (string)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'hcaptcha-force-show-captcha-edit',
					'hcaptcha-force-show-captcha-edit',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					'log_type' => 'abuse_filter',
					'abuse_filter_id' => 123
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => '123',
				'isBrowser' => null,
			] ],
			'hCaptcha failure, invalid Abuse Filter ID in the session object (non-numeric string)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					// Log type is other due to the inability
					// to determine the filter ID
					'log_type' => 'other',
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => 'foobar',
				'isBrowser' => null,
			] ],
			'hCaptcha failure, invalid Abuse Filter ID in the session object (negative string)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					// Log type is other due to the inability
					// to determine the filter ID
					'log_type' => 'other',
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => '-1',
				'isBrowser' => null,
			] ],
			'hCaptcha failure, invalid Abuse Filter ID in the session object (negative integer)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					// Log type is other due to the inability
					// to determine the filter ID
					'log_type' => 'other',
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => -1,
				'isBrowser' => null,
			] ],
			'hCaptcha failure, invalid Abuse Filter ID in the session object (zero, integer)' => [ [
				// Zero is forbidden by the schema
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					// Log type is other due to the inability
					// to determine the filter ID
					'log_type' => 'other',
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => 0,
				'isBrowser' => null,
			] ],
			'hCaptcha failure, invalid Abuse Filter ID in the session object (zero, string)' => [ [
				// Zero is forbidden by the schema
				'status' => Status::newFatal( new ApiMessage(
					'abusefilter-disallowed',
					'abusefilter-disallowed',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					// Log type is other due to the inability
					// to determine the filter ID
					'log_type' => 'other',
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => '0',
				'isBrowser' => null,
			] ],

			// Scenarios for error statuses
			//
			'captcha failure' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'captcha',
					'captcha'
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => null,
				'isBrowser' => null,
			] ],
			'spam blacklist failure' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'spamblacklist',
					'spamblacklist'
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => null,
				'isBrowser' => null,
			] ],

			// Scenarios providing x-is-browser
			//
			'hCaptcha failure, valid x-is-browser value (string, 1)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'hcaptcha-force-show-captcha-edit',
					'hcaptcha-force-show-captcha-edit',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					'log_type' => 'abuse_filter',
					'abuse_filter_id' => 123,
					'x_is_browser' => 1,
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => '123',
				'isBrowser' => '1',
			] ],
			'hCaptcha failure, valid x-is-browser value (string, 0)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'hcaptcha-force-show-captcha-edit',
					'hcaptcha-force-show-captcha-edit',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					'log_type' => 'abuse_filter',
					'abuse_filter_id' => 123,
					'x_is_browser' => 0,
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => '123',
				'isBrowser' => '0',
			] ],
			'hCaptcha failure, valid x-is-browser value (string, 123)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'hcaptcha-force-show-captcha-edit',
					'hcaptcha-force-show-captcha-edit',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					'log_type' => 'abuse_filter',
					'abuse_filter_id' => 123,
					'x_is_browser' => 123,
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => '123',
				'isBrowser' => '123',
			] ],
			'hCaptcha failure, valid x-is-browser value (integer, 0)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'hcaptcha-force-show-captcha-edit',
					'hcaptcha-force-show-captcha-edit',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					'log_type' => 'abuse_filter',
					'abuse_filter_id' => 123,
					'x_is_browser' => 0,
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => '123',
				'isBrowser' => 0,
			] ],
			'hCaptcha failure, valid x-is-browser value (integer, 1)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'hcaptcha-force-show-captcha-edit',
					'hcaptcha-force-show-captcha-edit',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					'log_type' => 'abuse_filter',
					'abuse_filter_id' => 123,
					'x_is_browser' => 1,
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => '123',
				'isBrowser' => 1,
			] ],
			'hCaptcha failure, valid x-is-browser value (integer, 123)' => [ [
				'status' => Status::newFatal( new ApiMessage(
					'hcaptcha-force-show-captcha-edit',
					'hcaptcha-force-show-captcha-edit',
				) ),
				'expectedRevisionId' => 101,
				'expectedLogType' => 'other',
				'expectedAFApiMessageDetails' => [
					'log_type' => 'abuse_filter',
					'abuse_filter_id' => 123,
					'x_is_browser' => 123,
				],
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'shouldSubmit' => true,
				'abuseFilterIdSessionData' => '123',
				'isBrowser' => 123,
			] ],

			// Scenario where the edit goes through (no error submitted)
			//
			'no errors' => [ [
				'status' => Status::newGood(),
				'expectedRevisionId' => 0,
				'expectedLogType' => 'other',
				'captchaTriggers' => [
					'edit' => $hCaptchaTrigger
				],
				'expectedAFApiMessageDetails' => [],
				'shouldSubmit' => false,
				'abuseFilterIdSessionData' => null,
				'isBrowser' => null,
			] ],
		];
	}

	/** @dataProvider provideOnLocalUserCreatedForNoCreatedEvent */
	public function testOnLocalUserCreatedForNoCreatedEvent(
		bool $confirmEditLoaded,
		bool $accountAutocreated,
		string $captchaUsedForAccountCreation,
		bool $captchaTriggeredForAccountCreation
	): void {
		$captchaFactory = null;
		if ( $confirmEditLoaded ) {
			$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );

			$this->overrideConfigValue(
				'CaptchaTriggers',
				[
					'create' => [
						'trigger' => $captchaTriggeredForAccountCreation,
						'class' => $captchaUsedForAccountCreation,
					],
				]
			);

			/** @var CaptchaFactory $captchaFactory */
			$captchaFactory = $this->getServiceContainer()->get( 'ConfirmEditCaptchaFactory' );
		}

		$mockExtensionRegistry = $this->createMock( ExtensionRegistry::class );
		$mockExtensionRegistry->method( 'isLoaded' )
			->with( 'ConfirmEdit' )
			->willReturn( $confirmEditLoaded );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$this->createNoOpMock( EventSubmitter::class ),
			$this->getServiceContainer()->getUserFactory(),
			$mockExtensionRegistry,
			$this->getServiceContainer()->get( 'EventBus.UserEntitySerializer' ),
			$captchaFactory
		);

		$captchaScoreHooks->onLocalUserCreated( $this->getTestUser()->getUser(), $accountAutocreated );
	}

	public static function provideOnLocalUserCreatedForNoCreatedEvent(): array {
		return [
			'ConfirmEdit not loaded' => [
				'confirmEditLoaded' => false,
				'accountAutocreated' => true,
				'captchaUsedForAccountCreation' => 'HCaptcha',
				'captchaTriggeredForAccountCreation' => false,
			],
			'Account auto-created' => [
				'confirmEditLoaded' => true,
				'accountAutocreated' => true,
				'captchaUsedForAccountCreation' => 'HCaptcha',
				'captchaTriggeredForAccountCreation' => true,
			],
			'FancyCaptcha used for account creation' => [
				'confirmEditLoaded' => true,
				'accountAutocreated' => false,
				'captchaUsedForAccountCreation' => 'FancyCaptcha',
				'captchaTriggeredForAccountCreation' => true,
			],
			'Captcha not needed for account creation' => [
				'confirmEditLoaded' => true,
				'accountAutocreated' => false,
				'captchaUsedForAccountCreation' => 'HCaptcha',
				'captchaTriggeredForAccountCreation' => false,
			],
		];
	}

	public function testOnLocalUserCreatedForCreatedEvent(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );
		$this->overrideConfigValue(
			'CaptchaTriggers',
			[
				'createaccount' => [
					'trigger' => true,
					'class' => 'HCaptcha',
				],
			]
		);

		$user = $this->getTestUser()->getUser();

		/** @var CaptchaFactory $captchaFactory */
		$captchaFactory = $this->getServiceContainer()->get( 'ConfirmEditCaptchaFactory' );
		$captchaInstance = $captchaFactory->getGlobalInstance( CaptchaTriggers::CREATE_ACCOUNT );
		$this->assertInstanceOf( HCaptcha::class, $captchaInstance );
		$captchaInstance->storeSessionScore(
			'hCaptcha-score',
			0.37,
			$user->getName()
		);

		RequestContext::getMain()->setRequest( new FauxRequest( [], true ) );

		$userEntitySerializer = $this->getServiceContainer()->get( 'EventBus.UserEntitySerializer' );

		$mockEventSubmitter = $this->createMock( EventSubmitter::class );
		$mockEventSubmitter->expects( $this->once() )
			->method( 'submit' )
			->with(
				'mediawiki.hcaptcha.risk_score',
				[
					'$schema' => '/analytics/mediawiki/hcaptcha/risk_score/1.5.0',
					'action' => 'createaccount',
					'wiki_id' => WikiMap::getCurrentWikiId(),
					'identifier' => $user->getId(),
					'identifier_type' => 'account',
					'performer' => $userEntitySerializer->toArray( $user ),
					'http' => [ 'method' => 'POST' ],
					'risk_score' => 0.37,
					'mw_entry_point' => MW_ENTRY_POINT,
				]
			);

		$captchaScoreHooks = new CaptchaScoreHooks(
			$mockEventSubmitter,
			$this->getServiceContainer()->getUserFactory(),
			$this->getServiceContainer()->getExtensionRegistry(),
			$this->getServiceContainer()->get( 'EventBus.UserEntitySerializer' ),
			$captchaFactory
		);

		$captchaScoreHooks->onLocalUserCreated( $this->getTestUser()->getUser(), false );
	}

	public function testPageSaveCompleteSubmitsEventWhenTriggerDisabled() {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );

		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [ 'trigger' => false, 'class' => 'HCaptcha' ] ]
		);
		$services = $this->getServiceContainer();
		/** @var CaptchaFactory $captchaFactory */
		$captchaFactory = $services->get( 'ConfirmEditCaptchaFactory' );
		/** @var HCaptcha $hCaptcha */
		$hCaptcha = $captchaFactory->getGlobalInstance( CaptchaTriggers::EDIT );

		$user = $this->createMock( User::class );
		$user->method( 'getName' )->willReturn( 'Editor' );
		$user->method( 'isRegistered' )->willReturn( false );
		$hCaptcha->storeSessionScore( 'hCaptcha-score', 0.3, 'Editor' );

		RequestContext::getMain()->setRequest( new FauxRequest( [], true ) );
		$userEntitySerializer = $services->get( 'EventBus.UserEntitySerializer' );
		$expectedEvent = $this->buildEventPayload( [
			'action' => CaptchaTriggers::EDIT,
			'identifier' => 7,
			'identifier_type' => 'revision',
			'performer' => $userEntitySerializer->toArray( $user ),
			'risk_score' => 0.3,
		] );

		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->once() )->method( 'submit' )
			->with( 'mediawiki.hcaptcha.risk_score', $expectedEvent );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getExtensionRegistry(),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$captchaFactory
		);
		$wikiPageMock = $this->createMock( WikiPage::class );
		$wikiPageMock->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$revisionRecordMock = $this->createMock( RevisionRecord::class );
		$revisionRecordMock->method( 'getId' )->willReturn( 7 );
		$editResultMock = $this->createMock( EditResult::class );
		$editResultMock->method( 'isNullEdit' )->willReturn( false );

		$captchaScoreHooks->onPageSaveComplete(
			$wikiPageMock, $user, '', '', $revisionRecordMock, $editResultMock
		);
	}

	public function testOnEditPageAttemptSaveAfterDoesNotSubmitForExemptUser(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );

		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [ 'trigger' => true, 'class' => 'HCaptcha' ] ]
		);
		$this->setGroupPermissions( 'sysop', 'skipcaptcha', true );
		$services = $this->getServiceContainer();
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();

		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->never() )->method( 'submit' );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getExtensionRegistry(),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$services->get( 'ConfirmEditCaptchaFactory' )
		);

		$contextMock = $this->createMock( RequestContext::class );
		$contextMock->method( 'getUser' )->willReturn( $user );
		$editPageMock = $this->createMock( EditPage::class );
		$editPageMock->method( 'getContext' )->willReturn( $contextMock );
		$editPageMock->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );

		$captchaScoreHooks->onEditPage__attemptSave_after(
			$editPageMock,
			Status::newFatal( 'captcha' ),
			[]
		);
	}

	public function testOnEditPageAttemptSaveAfterSubmitsForExemptUserWithForcedCaptcha(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );

		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [ 'trigger' => true, 'class' => 'HCaptcha' ] ]
		);
		$this->setGroupPermissions( 'sysop', 'skipcaptcha', true );
		$services = $this->getServiceContainer();
		/** @var CaptchaFactory $captchaFactory */
		$captchaFactory = $services->get( 'ConfirmEditCaptchaFactory' );
		/** @var HCaptcha $hCaptcha */
		$hCaptcha = $captchaFactory->getGlobalInstance( CaptchaTriggers::EDIT );
		$hCaptcha->setForceShowCaptcha( true );

		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$hCaptcha->storeSessionScore( 'hCaptcha-score', 0.37, $user->getName() );

		RequestContext::getMain()->setRequest( new FauxRequest( [], true ) );

		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->once() )->method( 'submit' );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getExtensionRegistry(),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$captchaFactory
		);

		$contextMock = $this->createMock( RequestContext::class );
		$contextMock->method( 'getUser' )->willReturn( $user );
		$editPageMock = $this->createMock( EditPage::class );
		$editPageMock->method( 'getContext' )->willReturn( $contextMock );
		$editPageMock->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$revisionMock = $this->createMock( RevisionRecord::class );
		$revisionMock->method( 'getId' )->willReturn( 101 );
		$editPageMock->method( 'getExpectedParentRevision' )->willReturn( $revisionMock );

		$captchaScoreHooks->onEditPage__attemptSave_after(
			$editPageMock,
			Status::newFatal( 'captcha' ),
			[]
		);
	}

	public function testOnLocalUserCreatedDoesNotSubmitForExemptUser(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );

		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'createaccount' => [ 'trigger' => true, 'class' => 'HCaptcha' ] ]
		);
		$this->setGroupPermissions( 'sysop', 'skipcaptcha', true );
		$services = $this->getServiceContainer();
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();

		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->never() )->method( 'submit' );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getExtensionRegistry(),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$services->get( 'ConfirmEditCaptchaFactory' )
		);

		$captchaScoreHooks->onLocalUserCreated( $user, false );
	}

	public function testPageSaveCompleteSubmitsEventForExemptUserWithForcedCaptcha() {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );

		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [ 'trigger' => true, 'class' => 'HCaptcha' ] ]
		);
		$this->setGroupPermissions( 'sysop', 'skipcaptcha', true );
		$services = $this->getServiceContainer();
		/** @var CaptchaFactory $captchaFactory */
		$captchaFactory = $services->get( 'ConfirmEditCaptchaFactory' );
		/** @var HCaptcha $hCaptcha */
		$hCaptcha = $captchaFactory->getGlobalInstance( CaptchaTriggers::EDIT );
		$hCaptcha->setForceShowCaptcha( true );

		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$hCaptcha->storeSessionScore( 'hCaptcha-score', 0.4, $user->getName() );

		RequestContext::getMain()->setRequest( new FauxRequest( [], true ) );
		$userEntitySerializer = $services->get( 'EventBus.UserEntitySerializer' );
		$expectedEvent = $this->buildEventPayload( [
			'action' => CaptchaTriggers::EDIT,
			'identifier' => 9,
			'identifier_type' => 'revision',
			'performer' => $userEntitySerializer->toArray( $user ),
			'risk_score' => 0.4,
		] );

		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->once() )->method( 'submit' )
			->with( 'mediawiki.hcaptcha.risk_score', $expectedEvent );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getExtensionRegistry(),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$captchaFactory
		);
		$wikiPageMock = $this->createMock( WikiPage::class );
		$wikiPageMock->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$revisionRecordMock = $this->createMock( RevisionRecord::class );
		$revisionRecordMock->method( 'getId' )->willReturn( 9 );
		$editResultMock = $this->createMock( EditResult::class );
		$editResultMock->method( 'isNullEdit' )->willReturn( false );

		$captchaScoreHooks->onPageSaveComplete(
			$wikiPageMock, $user, '', '', $revisionRecordMock, $editResultMock
		);
	}

	public function testPageSaveCompleteDoesNotSubmitForBot(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );

		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [ 'trigger' => true, 'class' => 'HCaptcha' ] ]
		);
		$this->setGroupPermissions( 'bot', 'bot', true );
		$services = $this->getServiceContainer();
		/** @var CaptchaFactory $captchaFactory */
		$captchaFactory = $services->get( 'ConfirmEditCaptchaFactory' );
		/** @var HCaptcha $hCaptcha */
		$hCaptcha = $captchaFactory->getGlobalInstance( CaptchaTriggers::EDIT );
		// A bot is never shown a captcha, even when one is forced.
		$hCaptcha->setForceShowCaptcha( true );

		$user = $this->getTestUser( [ 'bot' ] )->getUser();

		$eventSubmitterMock = $this->createMock( EventSubmitter::class );
		$eventSubmitterMock->expects( $this->never() )->method( 'submit' );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getExtensionRegistry(),
			$services->get( 'EventBus.UserEntitySerializer' ),
			$captchaFactory
		);
		$wikiPageMock = $this->createMock( WikiPage::class );
		$wikiPageMock->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$revisionRecordMock = $this->createMock( RevisionRecord::class );
		$revisionRecordMock->method( 'getId' )->willReturn( 9 );
		$editResultMock = $this->createMock( EditResult::class );
		$editResultMock->method( 'isNullEdit' )->willReturn( false );

		$captchaScoreHooks->onPageSaveComplete(
			$wikiPageMock, $user, '', '', $revisionRecordMock, $editResultMock
		);
	}

	private static function buildEventPayload( array $values ): array {
		return array_merge( [
			'$schema' => '/analytics/mediawiki/hcaptcha/risk_score/1.5.0',
			'wiki_id' => WikiMap::getCurrentWikiId(),
			'http' => [ 'method' => 'POST' ],
			'mw_entry_point' => MW_ENTRY_POINT,
		], $values );
	}
}
