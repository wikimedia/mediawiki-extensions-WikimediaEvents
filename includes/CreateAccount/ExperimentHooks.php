<?php

declare( strict_types = 1 );

namespace WikimediaEvents\CreateAccount;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\TestKitchen\Sdk\ExperimentManager;
use MediaWiki\MainConfigNames;
use MediaWiki\Minerva\Skins\SkinMinerva;
use MediaWiki\Skin\Skin;
use MediaWiki\SpecialPage\Hook\AuthChangeFormFieldsHook;
use MediaWiki\User\User;

class ExperimentHooks implements AuthChangeFormFieldsHook {

	public const ACCOUNT_CREATION_FORM_EXPERIMENT_V2 = 'we-1-8-account-creation-form-v2';

	public function __construct(
		private readonly Config $mainConfig,
		private readonly ExperimentManager $experimentManager,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onAuthChangeFormFields( $requests, $fieldInfo, &$formDescriptor, $action ): void {
		if ( $action !== AuthManager::ACTION_CREATE ) {
			return;
		}
		$context = RequestContext::getMain();

		if ( $this->shouldShowCreateAccountV2(
			$context->getUser(),
			$context->getSkin(),
		) ) {
			$context->getOutput()->addJsConfigVars( 'CreateAccountExperimentV2', true );
			if ( isset( $formDescriptor['password'] ) ) {
				$formDescriptor['password']['end-icon-class'] = 'growthexperiments-password-reveal-icon';
			}
			if ( isset( $formDescriptor['retype'] ) ) {
				$formDescriptor['retype']['end-icon-class'] = 'growthexperiments-password-reveal-icon';
			}
		}
	}

	private function shouldShowCreateAccountV2( ?User $user, Skin $skin ): bool {
		$isAnon = $user === null || $user->isAnon();
		// @phan-suppress-next-line PhanUndeclaredClassInstanceof
		$isMobile = $skin instanceof SkinMinerva;
		$isEnWiki = $this->mainConfig->get( MainConfigNames::DBname ) === 'enwiki';

		return $isAnon && $isMobile && $isEnWiki && $this->experimentManager->getExperiment(
				self::ACCOUNT_CREATION_FORM_EXPERIMENT_V2
			)->getAssignedGroup() === 'treatment';
	}
}
