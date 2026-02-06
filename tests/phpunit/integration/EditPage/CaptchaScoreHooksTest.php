<?php

namespace WikimediaEvents\Tests\Integration\EditPage;

use ExtensionRegistry;
use MediaWiki\Api\ApiMessage;
use MediaWiki\Context\RequestContext;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Extension\ConfirmEdit\AbuseFilter\CaptchaConsequence;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\hCaptcha\HCaptcha;
use MediaWiki\Extension\ConfirmEdit\Hooks;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Page\WikiPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Status\Status;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use WikimediaEvents\EditPage\CaptchaScoreHooks;

/**
 * @covers \WikimediaEvents\EditPage\CaptchaScoreHooks
 * @group Database
 */
class CaptchaScoreHooksTest extends MediaWikiIntegrationTestCase {

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
			$this->getServiceContainer()->getUserGroupManager(),
			$this->getServiceContainer()->getCentralIdLookup(),
			$mockExtensionRegistry
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
			'Captcha trigger disabled' => [
				[ 'edit' => [ 'trigger' => false, 'class' => 'HCaptcha' ] ],
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
			$services->getUserGroupManager(),
			$services->getCentralIdLookup(),
			$services->getExtensionRegistry()
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
		$this->overrideConfigValue(
			'GroupPermissions',
			[ 'sysop' => [ 'skipcaptcha' => true ] ]
		);
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		/** @var HCaptcha $simpleCaptcha */
		$simpleCaptcha = Hooks::getInstance( CaptchaTriggers::EDIT );
		$simpleCaptcha->storeSessionScore( 'hCaptcha-score', 0.1, $user->getName() );

		$captchaScoreHooks = new CaptchaScoreHooks(
			$eventSubmitterMock,
			$services->getUserFactory(),
			$services->getUserGroupManager(),
			$services->getCentralIdLookup(),
			$services->getExtensionRegistry()
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

		$userEntitySerializer = new UserEntitySerializer(
			$services->getUserFactory(),
			$services->getUserGroupManager(),
			$services->getCentralIdLookup()
		);
		$expectedPerformer = $userEntitySerializer->toArray( $user );

		$expectedEvent = [
			'$schema' => '/analytics/mediawiki/hcaptcha/risk_score/1.3.0',
			'action' => $isNullEdit ? 'null_edit' : CaptchaTriggers::EDIT,
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
			$services->getUserFactory(),
			$services->getUserGroupManager(),
			$services->getCentralIdLookup(),
			$services->getExtensionRegistry()
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
		] = $params;

		$this->overrideConfigValue( 'CaptchaTriggers', $captchaTriggers );

		$services = $this->getServiceContainer();
		$user = $this->getTestUser()->getUser();

		$userFactoryMock = $this->createMock( UserFactory::class );
		$userFactoryMock->method( 'newFromUserIdentity' )->willReturn( $user );

		$captchaInstance = Hooks::getInstance( CaptchaTriggers::EDIT );
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
			$userEntitySerializer = new UserEntitySerializer(
				$userFactoryMock,
				$services->getUserGroupManager(),
				$services->getCentralIdLookup()
			);
			$expectedEvent = [
				'$schema' => '/analytics/mediawiki/hcaptcha/risk_score/1.3.0',
				'action' => 'failed_edit',
				'wiki_id' => WikiMap::getCurrentWikiId(),
				'identifier' => $expectedRevisionId,
				'identifier_type' => 'latest_revision',
				'performer' => $userEntitySerializer->toArray( $user ),
				'http' => [
					'method' => 'POST',
				],
				'risk_score' => 0.37,
				'mw_entry_point' => MW_ENTRY_POINT,
				'editing_session_id' => 'session-42',
				'log_type' => $expectedLogType,
			];
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
			$services->getUserGroupManager(),
			$services->getCentralIdLookup(),
			$services->getExtensionRegistry()
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
			] ],
			'hCaptcha trigger disabled' => [ [
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
						[ 'trigger' => false ]
					),
				],
				'shouldSubmit' => false,
				'abuseFilterIdSessionData' => null,
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
			] ],
		];
	}
}
