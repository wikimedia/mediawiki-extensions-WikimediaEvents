<?php

namespace WikimediaEvents;

use ErrorPageError;
use ExtensionRegistry;
use HTMLForm;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\Utils\MWTimestamp;
use Message;
use RequestContext;

/**
 * Manage the X-Wikimedia-Debug cookie, to enable verbose logging on production servers.
 *
 * <https://wikitech.wikimedia.org/wiki/WikimediaDebug>
 */
class SpecialWikimediaDebug extends UnlistedSpecialPage {

	/**
	 * How far expiry is allowed to be in the future.
	 *
	 * Using the debug cookie unnecessarily is both extra server load (since it circumvents
	 * the edge cache) and logspam on the debug servers which are relied on to verify backports.
	 * We make it hard to accidentally (or intentionally) set this cookie for a very long time.
	 *
	 * By default we set the cookie for 1 hour. It can be renewed by visiting this page.
	 *
	 * @see XWikimediaDebug::MAX_EXPIRY in operations/mediawiki-config.git,
	 * which explicitly rejects cookies that have expiry longer than 24 hours.
	 *
	 */
	private const MAX_EXPIRY = 24 * 3600;
	private const DEFAULT_EXPIRY = 3600;

	private ExtensionRegistry $extensionRegistry;

	public function __construct(
		ExtensionRegistry $extensionRegistry
	) {
		parent::__construct( 'WikimediaDebug' );
		$this->extensionRegistry = $extensionRegistry;
	}

	/**
	 * @param string|null $par Subpage
	 */
	public function execute( $par ) {
		if ( !$this->getConfig()->get( 'WMEWikimediaDebugBackend' ) ) {
			throw new ErrorPageError( 'badaccess', 'wikimediaevents-special-wikimediadebug-notenabled' );
		}
		$this->setHeaders();
		$this->outputHeader();

		$cookieData = $this->getCookie();

		$form = HTMLForm::factory( 'ooui', [], $this->getContext() );
		$form->setSubmitCallback( [ $this, 'submit' ] );
		if ( !$cookieData ) {
			$form->setSubmitTextMsg( $this->msg(
				'wikimediaevents-special-wikimediadebug-submit-set',
				Message::durationParam( self::DEFAULT_EXPIRY ) ) );
			$form->addHeaderHtml( $this->msg(
				'wikimediaevents-special-wikimediadebug-header-inactive',
				$this->getCookieDomainForDisplay()
			)->parseAsBlock() );
		} else {
			$form->setSubmitDestructive();
			$form->setSubmitName( 'clear' );
			$form->setSubmitTextMsg( $this->msg( 'wikimediaevents-special-wikimediadebug-submit-clear' ) );
			$form->addHeaderHtml( $this->msg(
				'wikimediaevents-special-wikimediadebug-header-active',
				$this->getCookieDomainForDisplay(),
				Message::dateTimeParam( $cookieData['expire'] )
			)->parseAsBlock() );

			$form->addButton( [
				'name' => 'renew',
				'value' => '1',
				'label-message' => [ 'wikimediaevents-special-wikimediadebug-submit-renew',
					Message::durationParam( self::DEFAULT_EXPIRY )
				],
			] );
		}

		$form->show();
	}

	/**
	 * @param array $data
	 * @param HTMLForm $form
	 * @return true
	 */
	public function submit( array $data, HTMLForm $form ) {
		if ( $form->getRequest()->getCheck( 'clear' ) ) {
			$this->clearCookie();
		} else {
			$this->setCookie( [
				'backend' => $this->getConfig()->get( 'WMEWikimediaDebugBackend' ),
				'log' => true,
			] );
		}
		$this->getOutput()->redirect( $this->getPageTitle()->getLocalURL() );
		return true;
	}

	/** @inheritDoc */
	public function getDescription() {
		return $this->msg( 'wikimediaevents-special-wikimediadebug-desc' );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'specialpages-group-developer';
	}

	/** @inheritDoc */
	public function addHelpLink( $to, $overrideBaseUrl = false ) {
		$this->getOutput()->addHelpLink( 'https://wikitech.wikimedia.org/wiki/WikimediaDebug', true );
	}

	public function getCookie(): ?array {
		$cookieString = RequestContext::getMain()->getRequest()->getCookie( 'X-Wikimedia-Debug', '' );
		if ( $cookieString === null ) {
			return null;
		}
		$cookieData = [];
		foreach ( explode( ';', rawurldecode( $cookieString ) ) as $pair ) {
			$pair = explode( '=', $pair, 2 );
			if ( count( $pair ) === 2 ) {
				$cookieData[trim( $pair[0] )] = trim( $pair[1] );
			} else {
				$cookieData[trim( $pair[0] )] = true;
			}
		}
		$expire = $cookieData['expire'] ?? 0;
		if ( $expire < MWTimestamp::time() || $expire > MWTimestamp::time() + self::MAX_EXPIRY ) {
			// Proactively delete the cookie for the benefit of edge routing.
			// It is ignored by wmf-config either way.
			$this->clearCookie();
			return null;
		}
		return $cookieData;
	}

	private function setCookie( array $cookieData ): void {
		// These cookies get decoded by Varnish, which is generally not well-equipped to do
		// decoding, so the current code relies on the details of the logic here; specifically,
		// on = and ; being the only two characters that get percent-encoded. If you change that,
		// you'll need to update the VCL in the operations/puppet repo.
		$expiry = time() + self::DEFAULT_EXPIRY;
		$cookieData['expire'] = $expiry;
		$cookieStringParts = [];
		foreach ( $cookieData as $key => $value ) {
			if ( $value === true ) {
				$cookieStringParts[] = $key;
			} else {
				$cookieStringParts[] = $key . '=' . $value;
			}
		}
		$this->getRequest()->response()->setCookie(
			'X-Wikimedia-Debug',
			implode( ';', $cookieStringParts ),
			$expiry,
			$this->getCookieOptions()
		);
	}

	private function clearCookie(): void {
		$this->getRequest()->response()->clearCookie(
			'X-Wikimedia-Debug',
			$this->getCookieOptions()
		);
	}

	private function getCookieOptions(): array {
		$options = [
			'prefix' => '',
		];
		// SameSite will prevent the cookie from being set if it's not also Secure.
		// Cannot happen in production but makes local development confusing.
		if ( $this->getConfig()->get( MainConfigNames::CookieSecure ) ) {
			$options['sameSite'] = 'none';
		}
		if ( $this->extensionRegistry->isLoaded( 'CentralAuth' )
			&& $this->getConfig()->get( 'CentralAuthCookieDomain' )
		) {
			$options['domain'] = $this->getConfig()->get( 'CentralAuthCookieDomain' );
		}
		return $options;
	}

	private function getCookieDomainForDisplay(): string {
		$cookieDomain = '';
		if ( $this->extensionRegistry->isLoaded( 'CentralAuth' ) ) {
			$cookieDomain = $this->getConfig()->get( 'CentralAuthCookieDomain' );
		}
		$cookieDomain = $cookieDomain
			?: $this->getConfig()->get( MainConfigNames::CookieDomain )
			?: (string)$this->getRequest()->getHeader( 'Host' );
		return $cookieDomain;
	}

}
