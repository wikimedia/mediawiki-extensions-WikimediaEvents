<?php

namespace WikimediaEvents;

use ApiBase;
use Title;
use Wikimedia\ParamValidator\ParamValidator;

class ApiWikimediaEventsBlockedEdit extends ApiBase {

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$user = $this->getUser();
		$params = $this->extractRequestParams();
		$title = Title::newFromText( $params['page'] );

		if ( !$title ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['page'] ) ] );
		}

		BlockUtils::logBlockedEditAttempt( $user, $title, $params['interface'], $params['platform'] );
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			'page' => [
				ParamValidator::PARAM_REQUIRED => true,
			],
			'interface' => [
				ParamValidator::PARAM_REQUIRED => true,
			],
			'platform' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => [ 'desktop', 'mobile' ],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function isInternal() {
		return true;
	}

}
