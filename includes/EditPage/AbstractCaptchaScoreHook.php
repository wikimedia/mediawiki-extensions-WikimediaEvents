<?php

declare( strict_types = 1 );

namespace WikimediaEvents\EditPage;

use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Request\WebRequest;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;

/**
 * Shared base for hCaptcha risk score hook handlers.
 */
abstract class AbstractCaptchaScoreHook {

	protected const STREAM = 'mediawiki.hcaptcha.risk_score';
	protected const SCHEMA = '/analytics/mediawiki/hcaptcha/risk_score/1.4.0';

	public function __construct(
		protected readonly UserEntitySerializer $userEntitySerializer,
	) {
	}

	/**
	 * Build base event payload.
	 */
	protected function buildEventPayload(
		string $action,
		int $identifier,
		string $identifierType,
		UserIdentity $user,
		float $riskScore,
		WebRequest $request,
		?string $logType = null,
		string $pageViewId = '',
	): array {
		$event = [
			'$schema' => self::SCHEMA,
			'action' => $action,
			'http' => [
				'method' => $request->getMethod(),
			],
			'identifier' => $identifier,
			'identifier_type' => $identifierType,
			'performer' => $this->userEntitySerializer->toArray( $user ),
			'risk_score' => $riskScore,
			'mw_entry_point' => MW_ENTRY_POINT,
			'wiki_id' => WikiMap::getCurrentWikiId(),
		];

		$isBrowser = $request->getHeader( 'x-is-browser' );
		if ( $isBrowser !== false ) {
			$value = $this->castToNonNegativeInteger( $isBrowser );
			if ( $value !== null ) {
				$event['x_is_browser'] = $value;
			}
		}

		if ( $logType !== null ) {
			$event['log_type'] = $logType;
		}

		$editingSessionId = $request->getRawVal( 'editingStatsId' );
		if ( $editingSessionId ) {
			$event['editing_session_id'] = $editingSessionId;
		}

		if ( $pageViewId !== '' ) {
			$event['page_view_id'] = $pageViewId;
		}

		return $event;
	}

	/**
	 * Validates if a value represents a valid natural number or zero, returning
	 * its value as an integer if it is, or null if it is not.
	 *
	 * This is needed because the schema defines some fields (such as
	 * abuse_filter_id and x_is_browser) as optional integers, but they may be
	 * initially read as strings instead.
	 *
	 * @param mixed $value Raw value to cast.
	 * @return int|null Integer value, null if $value does not represent an int.
	 */
	protected function castToNonNegativeInteger( mixed $value ): ?int {
		if ( !is_numeric( $value ) ) {
			return null;
		}

		// Cast $value to an integer and ensure it's positive or zero (T418505).
		$intVal = (int)$value;
		return ( $intVal >= 0 ? $intVal : null );
	}
}
