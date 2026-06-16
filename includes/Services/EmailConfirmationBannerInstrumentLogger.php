<?php

namespace WikimediaEvents\Services;

use MediaWiki\Extension\TestKitchen\Sdk\InstrumentManagerInterface;

/**
 * Sends long-term product-health events for the email confirmation banner to a single Test Kitchen
 * instrument. The same instrument receives the client-side banner events (impression, click) and
 * the server-side email lifecycle events (email_confirmed, email_invalidated).
 */
class EmailConfirmationBannerInstrumentLogger {
	private const INSTRUMENT_NAME = 'email-confirmation-banner-2026-06';

	public function __construct(
		private readonly InstrumentManagerInterface $instrumentManager
	) {
	}

	/**
	 * @param string $action Interaction action, e.g. 'email_confirmed' or 'email_invalidated'.
	 * @param array $data Additional interaction data.
	 */
	public function log( string $action, array $data = [] ): void {
		$this->instrumentManager
			->getInstrument( self::INSTRUMENT_NAME )
			->send( $action, $data );
	}
}
