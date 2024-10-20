<?php

namespace WikimediaEvents;

use MediaWiki\Api\ApiBase;
use MediaWiki\Title\Title;
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

				// See https://gerrit.wikimedia.org/g/schemas/event/secondary/+/192e1a497d16b3da22817177e7676e342a4494a7/jsonschema/analytics/mediawiki/editattemptsblocked/current.yaml#44
				ParamValidator::PARAM_TYPE => [
					'wikieditor',
					'visualeditor',
					'mobilefrontend',
					'discussiontools',
					'other',
				],
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
