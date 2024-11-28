<?php
namespace WikimediaEvents\Tests\Integration\TemporaryAccounts;

use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use PageStabilityProtectForm;

/**
 * @group Database
 * @covers \WikimediaEvents\TemporaryAccounts\FlaggedRevsTemporaryAccountsInstrumentation
 * @covers \WikimediaEvents\TemporaryAccounts\AbstractTemporaryAccountsInstrumentation
 */
class FlaggedRevsTemporaryAccountsInstrumentationTest extends MediaWikiIntegrationTestCase {

	use TemporaryAccountsInstrumentationTrait;

	protected function setUp(): void {
		parent::setUp();

		$this->markTestSkippedIfExtensionNotLoaded( 'FlaggedRevs' );
		$this->overrideConfigValue( 'FlaggedRevsProtection', true );
		$this->overrideConfigValue( 'FlaggedRevsRestrictionLevels', [ 'autoconfirmed' ] );
	}

	public function testShouldTrackPageProtectionRateForExistingPage() {
		$page = $this->getExistingTestPage();
		$user = $this->getTestSysop()->getUser();
		$title = $page->getTitle();

		$status = $this->doChangeStabilitySettings(
			$user, $title, 'autoconfirmed', 'Setting stabilisation settings to be autoconfirmed', '1 year'
		);
		$this->assertTrue( $status );
		$this->assertCounterIncremented( 'users_page_protect_total', [ 'FlaggedRevs' ] );
	}

	public function testShouldNotTrackPageUnprotection() {
		$page = $this->getExistingTestPage();
		$user = $this->getTestSysop()->getUser();
		$title = $page->getTitle();

		$firstStatus = $this->doChangeStabilitySettings(
			$user, $title, 'autoconfirmed', 'Setting stabilisation settings to be autoconfirmed', '1 year'
		);
		$this->assertTrue( $firstStatus );
		$secondStatus = $this->doChangeStabilitySettings(
			$user, $title, '', 'Undoing stabilisation settings change', 'infinity'
		);
		$this->assertTrue( $secondStatus );

		// Should only result in one increment of the counter, due to having to protect the page to unprotect it.
		$this->assertCounterIncremented( 'users_page_protect_total', [ 'FlaggedRevs' ] );
	}

	/**
	 * Convenience function to make stability settings changes to a given title.
	 *
	 * @param User $user
	 * @param Title $title
	 * @param string $permission
	 * @param string $reason
	 * @param string $expiry
	 * @return string|true
	 */
	private function doChangeStabilitySettings(
		User $user, Title $title, string $permission, string $reason, string $expiry
	) {
		$form = new PageStabilityProtectForm( $user );

		$form->setTitle( $title );
		$form->setAutoreview( $permission );
		$form->setReasonExtra( $reason );
		$form->setReasonSelection( 'other' );
		$form->setExpiryCustom( $expiry );

		$form->ready();

		return $form->submit();
	}
}
