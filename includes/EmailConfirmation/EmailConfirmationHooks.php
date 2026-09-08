<?php

namespace WikimediaEvents\EmailConfirmation;

use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Extension\TestKitchen\Sdk\ExperimentManagerInterface;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\User\Hook\ConfirmEmailCompleteHook;
use MediaWiki\User\Hook\InvalidateEmailCompleteHook;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;

class EmailConfirmationHooks implements
	BeforePageDisplayHook,
	ConfirmEmailCompleteHook,
	InvalidateEmailCompleteHook,
	LocalUserCreatedHook
{
	public function __construct(
		private readonly Config $config,
		private readonly ExperimentManagerInterface $experimentManager,
		private readonly EmailConfirmationBannerInstrumentLogger $emailConfirmationBannerInstrumentLogger
	) {
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$user = $out->getUser();

		// Load the email confirmation banner instrument when the banner is shown.
		// Mirrors the banner visibility conditions in core's
		// \MediaWiki\Mail\ConfirmEmail\EmailConfirmationBannerHandler::shouldShowBanner() so the module
		// (and its impression/click events) only loads on pages where the banner actually renders.
		if (
			$this->config->get( MainConfigNames::EmailConfirmationBanner ) &&
			$this->config->get( MainConfigNames::EmailAuthentication ) &&
			$user->isNamed() &&
			$user->getEmail() !== '' &&
			!$user->isEmailConfirmed() &&
			!$out->getTitle()?->isSpecial( 'Confirmemail' )
		) {
			$out->addModules( 'ext.wikimediaEvents.emailConfirmationBanner' );
		}

		// Add a JS config var if the user is eligible for the email-confirmation-* experiments
		if ( $this->isUserEligibleForEmailConfirmationExperiment( $user ) ) {
			$out->addJsConfigVars( [
				'wgWMEUserEligibleForEmailExperiment' => true
			] );
		}
	}

	/** @inheritDoc */
	public function onConfirmEmailComplete( $user ): void {
		$this->emailConfirmationBannerInstrumentLogger->log( 'email_confirmed' );

		// Send events for the email-confirmation-enforcement-* experiments.
		// Check eligibility but don't check for unconfirmed email, since the user has just confirmed their email
		// Also don't check for same-wiki-ness, if the user confirms their email anywhere we want to capture that
		if ( $this->isUserEligibleForEmailConfirmationExperiment(
				$user, ignoreEmail: true, ignoreCreationWiki: true
		) ) {
			$delayed = $this->experimentManager->getExperiment( 'email-confirmation-enforcement-delayed-pilot' );
			$delayed->send( 'email_confirmed' );

			$upfront = $this->experimentManager->getExperiment( 'email-confirmation-enforcement-upfront-pilot' );
			$upfront->send( 'email_confirmed' );
		}
	}

	/** @inheritDoc */
	public function onInvalidateEmailComplete( $user ): void {
		$this->emailConfirmationBannerInstrumentLogger->log( 'email_invalidated' );
	}

	/** @inheritDoc */
	public function onLocalUserCreated( $user, $autocreated ) {
		// HACK: Ensure that the use the ExperimentManager usage below, and any later ones, use the
		// new user rather than the previous one. However, if this is a user creating an account for
		// another user, then we don't want this state to stick, we need to restore the old one.
		$oldUser = RequestContext::getMain()->getUser();
		// @phan-suppress-next-line PhanUndeclaredMethod
		$this->experimentManager->updateUser( $user );
		if (
			!$autocreated &&
			// We don't need to check whether the user was created on this wiki, because !$autocreated covers that
			$this->isUserEligibleForEmailConfirmationExperiment( $user, ignoreCreationWiki: true )
		) {
			$experiment = $this->experimentManager->getExperiment( 'email-confirmation-enforcement-upfront-pilot' );
			$experiment->sendExposure();
		}
		if ( $oldUser->isNamed() ) {
			// If this account was created for someone else, restore the previous user
			// @phan-suppress-next-line PhanUndeclaredMethod
			$this->experimentManager->updateUser( $oldUser );
		}
	}

	private function isUserEligibleForEmailConfirmationExperiment(
		User $user,
		bool $ignoreEmail = false,
		bool $ignoreCreationWiki = false
	): bool {
		return $user->isNamed() &&
			( $ignoreEmail || $user->getEmail() !== '' ) &&
			( $ignoreEmail || !$user->isEmailConfirmed() ) &&
			!$user->isBot() &&
			// User was created after the experiment started
			$user->getRegistration() > wfTimestamp( TS_MW, '2026-09-04 00:00:00' ) &&
			// User was created on this wiki
			(
				$ignoreCreationWiki ||
				CentralAuthUser::getInstance( $user )?->getHomeWiki() === WikiMap::getCurrentWikiId()
			);
	}
}
