<?php

namespace WikimediaEvents\Tests\Integration\Api;

use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use TextContent;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \WikimediaEvents\Api\ApiWikimediaEventsHCaptchaEditAttempt
 * @group Database
 */
class ApiWikimediaEventsHCaptchaEditTest extends ApiTestCase {

	/**
	 * @dataProvider provideApiEndpointLogsDiff
	 * @throws ApiUsageException
	 */
	public function testApiEndpointLogsDiff( array $providerParams ) {
		[
			'editingSessionId' => $editingSessionId,
			'revisionId' => $revisionId,
			'shouldPageExist' => $shouldPageExist,
			'shouldSubmitEvent' => $shouldSubmitEvent,
			'oldText' => $oldText,
			'newText' => $newText,
		] = $providerParams;

		$title = $this->getNonexistingTestPage()->getTitle();
		if ( $shouldPageExist ) {
			$this->editPage( $title, $oldText );
		}

		$user = $this->getTestUser()->getUser();

		$expectedPageId = $shouldPageExist ? $title->getId() : 0;
		$services = $this->getServiceContainer();
		$userEntitySerializer = new UserEntitySerializer(
			$services->getUserFactory(),
			$services->getUserGroupManager(),
			$services->getCentralIdLookup()
		);
		$expectedPerformer = $userEntitySerializer->toArray( $user );

		$mockEventSubmitter = $this->createMock( EventSubmitter::class );
		$this->setService( 'EventLogging.EventSubmitter', $mockEventSubmitter );

		$capturedEventData = null;

		if ( $shouldSubmitEvent ) {
			$mockEventSubmitter->expects( $this->once() )
				->method( 'submit' )
				->with( 'mediawiki.hcaptcha.edit', $this->anything() )
				->willReturnCallback( static function ( $stream, $eventData ) use ( &$capturedEventData ) {
					$capturedEventData = $eventData;
				} );
		} else {
			$mockEventSubmitter->expects( $this->never() )
				->method( 'submit' );
		}

		// Make API request
		$params = [
			'action' => 'wikimediaeventshcaptchaeditattempt',
			'title' => $title->getPrefixedText(),
			'proposed_content' => $newText,
			'editing_session_id' => $editingSessionId,
			'revision_id' => $revisionId,
		];

		$result = $this->doApiRequest( $params, null, false, $user );

		if ( $shouldSubmitEvent ) {
			// Verify API response
			$this->assertArrayHasKey( 'result', $result[0] );
			$this->assertEquals( 'success', $result[0]['result'] );

			// Verify event data was captured
			$this->assertNotNull( $capturedEventData, 'Event data should be captured' );
			$this->assertIsArray( $capturedEventData, 'Event data should be an array' );

			$this->assertArrayHasKey( '$schema', $capturedEventData, 'Event data should have $schema field' );
			$this->assertEquals(
				'/analytics/mediawiki/hcaptcha/edit/1.0.0',
				$capturedEventData['$schema'],
				'Schema should match expected value'
			);

			$this->assertArrayHasKey( 'page_id', $capturedEventData, 'Event data should have page_id field' );
			$this->assertEquals(
				$expectedPageId,
				$capturedEventData['page_id'],
				'Page ID should match expected value'
			);

			$this->assertArrayHasKey( 'performer', $capturedEventData, 'Event data should have performer field' );
			$this->assertIsArray( $capturedEventData['performer'], 'Performer should be an array' );
			$this->assertEquals(
				$expectedPerformer,
				$capturedEventData['performer'],
				'Performer data should match expected value'
			);

			$this->assertArrayHasKey(
				'proposed_content_diff',
				$capturedEventData,
				'Event data should have proposed_content_diff field'
			);
			$this->assertIsString(
				$capturedEventData['proposed_content_diff'],
				'Proposed content diff should be a string'
			);
			$capturedDiff = $capturedEventData['proposed_content_diff'];

			$this->assertArrayHasKey( 'wiki_id', $capturedEventData, 'Event data should have wiki_id field' );
			$this->assertEquals(
				WikiMap::getCurrentWikiId(),
				$capturedEventData['wiki_id'],
				'Wiki ID should match current wiki ID'
			);

			$this->assertArrayHasKey(
				'editing_session_id',
				$capturedEventData,
				'Event data should have editing_session_id field'
			);
			$this->assertEquals(
				$editingSessionId,
				$capturedEventData['editing_session_id'],
				'Editing session ID should match expected value'
			);

			$this->assertArrayHasKey( 'revision_id', $capturedEventData, 'Event data should have revision_id field' );
			// Verify revision_id
			if ( $shouldPageExist ) {
				$this->assertNotNull(
					$capturedEventData['revision_id'],
					'Revision ID should be present for existing pages'
				);
				$this->assertIsInt( $capturedEventData['revision_id'], 'Revision ID should be an integer' );
			} else {
				$this->assertSame( 0, $capturedEventData['revision_id'], 'Revision ID should be 0 for new pages' );
			}
			$this->assertEquals(
				$revisionId,
				$capturedEventData['revision_id'],
				'Revision ID should match expected value'
			);

			// Verify diff content
			if ( $shouldPageExist ) {
				// For existing pages, diff should show changes (old -> new)
				$this->assertNotEmpty(
					$capturedDiff,
					'Diff should not be empty for edits to existing pages'
				);
				// Diff should contain new content indicators
				// (unified diff format uses + for additions)
				$this->assertStringContainsString(
					'+',
					$capturedDiff,
					'Diff should contain additions'
				);
				$normalizedOld = TextContent::normalizeLineEndings( $oldText );
				$normalizedNew = TextContent::normalizeLineEndings( $newText );
				if ( str_contains( $normalizedNew, $normalizedOld ) ) {
					// Verify that no line starts with '-' (except file header '---')
					// Standard unified diff lines start with space, +, or -.
					// Explode by newline and look for lines starting with '-' that aren't the header.
					$diffLines = explode( "\n", $capturedDiff );
					foreach ( $diffLines as $diffLine ) {
						if ( str_starts_with( $diffLine, '-' ) && !str_starts_with( $diffLine, '---' ) ) {
							$this->fail(
								"Diff contained unexpected deletion: '$diffLine'. \n"
							);
						}
					}
				}
			} else {
				// For new pages, diff shows empty old content -> new content
				$diffLines = explode( "\n", $capturedDiff );
				$addedLines = array_filter( $diffLines, static function ( $line ) {
					return strpos( $line, '+' ) === 0;
				} );
				$this->assertNotEmpty(
					$addedLines,
					'Diff for new pages should show all lines as additions'
				);
			}

			// Verify new content appears in diff
			$normalizedNewText = TextContent::normalizeLineEndings( $newText );
			$newLines = explode( "\n", $normalizedNewText );
			foreach ( $newLines as $line ) {
				$this->assertStringContainsString( $line, $capturedDiff );
			}
		}
	}

	public static function provideApiEndpointLogsDiff(): array {
		$oldText = "Old content line 1\nOld content line 2";
		$newText = "New content line 1\nNew content line 2\nNew content line 3";
		return [
			'Valid request with existing page - should submit event with diff' => [ [
				'editingSessionId' => 'test-session-001',
				'revisionId' => 1,
				'shouldPageExist' => true,
				'shouldSubmitEvent' => true,
				'oldText' => $oldText,
				'newText' => $newText,

			] ],
			'Valid request with existing page and editing session ID - should submit event'
				=> [ [
				'editingSessionId' => 'test-session-002',
				'revisionId' => 1,
				'shouldPageExist' => true,
				'shouldSubmitEvent' => true,
				'oldText' => $oldText,
				'newText' => $newText,
			] ],
			'Valid request but page does not exist - should still submit event' => [ [
				'editingSessionId' => '',
				'revisionId' => 0,
				'shouldPageExist' => false,
				'shouldSubmitEvent' => true,
				'oldText' => '',
				'newText' => $newText,
			] ],
			'Valid request with mixed line endings (CRLF) - should normalize and not cause total rewrite' => [ [
				'editingSessionId' => 'test-session-windows-eol',
				'revisionId' => 1,
				'shouldPageExist' => true,
				'shouldSubmitEvent' => true,
				'oldText' => "Line 1\nLine 2",
				'newText' => "Line 1\r\nLine 2\r\nLine 3",
			] ],
		];
	}

	/**
	 * @dataProvider provideAntiAbuseChecks
	 */
	public function testAntiAbuseChecks(
		bool $hasSkipCaptcha,
		bool $isRateLimited,
		string $editingSessionId,
		int $revisionId,
		bool $pageExists,
		string $oldText,
		string $newText
	) {
		$title = $this->getNonexistingTestPage()->getTitle();
		if ( $pageExists ) {
			$this->editPage( $title, $oldText );
		}

		// Get a real user as base for IDs
		$baseUser = $this->getTestUser()->getUser();

		// Create partial mock - only mock the methods we need to control
		$user = $this->createPartialMock( User::class, [
			'isAllowed',
			'pingLimiter',
			'getId',
			'getEditCount'
		] );

		// Set up the real values we need
		$user->method( 'getId' )->willReturn( $baseUser->getId() );
		$user->method( 'getEditCount' )->willReturn( $baseUser->getEditCount() ?: 0 );

		// Set up the security check methods based on test parameters
		$user->method( 'isAllowed' )
			->with( 'skipcaptcha' )
			->willReturn( $hasSkipCaptcha );

		$user->method( 'pingLimiter' )
			->with( 'wikimediaevents-hcaptcha-diff-logging' )
			->willReturn( $isRateLimited );

		// Set mFrom property to avoid "Unrecognised value for User->mFrom" error
		// This is required for session handling in doApiRequest
		$userWrapper = TestingAccessWrapper::newFromObject( $user );
		$baseUserWrapper = TestingAccessWrapper::newFromObject( $baseUser );
		$userWrapper->mFrom = $baseUserWrapper->mFrom ?? 'default';

		$mockEventSubmitter = $this->createMock( EventSubmitter::class );
		$this->setService( 'EventLogging.EventSubmitter', $mockEventSubmitter );

		// If a user fails anti-abuse checks, then no event should be submitted
		$mockEventSubmitter->expects( $this->never() )
			->method( 'submit' );

		$params = [
			'action' => 'wikimediaeventshcaptchaeditattempt',
			'title' => $title->getPrefixedText(),
			'proposed_content' => $newText,
			'editing_session_id' => $editingSessionId,
			'revision_id' => $revisionId
		];

		// Should throw ApiUsageException
		$this->expectException( ApiUsageException::class );
		$this->doApiRequest( $params, null, false, $user );
	}

	public static function provideAntiAbuseChecks(): array {
		$oldText = "Old content line 1\nOld content line 2";
		$newText = "New content line 1\nNew content line 2\nNew content line 3";
		return [
			'User with skipcaptcha should get error' => [
				'hasSkipCaptcha' => true,
				'isRateLimited' => false,
				'editingSessionId' => 'test-session',
				'revisionId' => 1,
				'pageExists' => true,
				'oldText' => $oldText,
				'newText' => $newText,
			],
			'Rate-limited user should get error' => [
				'hasSkipCaptcha' => false,
				'isRateLimited' => true,
				'editingSessionId' => 'test-session',
				'revisionId' => 1,
				'pageExists' => true,
				'oldText' => $oldText,
				'newText' => $newText,
			],
		];
	}

}
