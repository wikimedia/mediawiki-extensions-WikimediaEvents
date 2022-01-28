/*!
 * ULS Event logger
 *
 * See https://meta.wikimedia.org/wiki/Schema:UniversalLanguageSelector
 *
 * @since 2013.08
 *
 * Copyright (C) 2012-2013 Alolita Sharma, Amir Aharoni, Arun Ganesh, Brandon Harris,
 * Niklas Laxström, Pau Giner, Santhosh Thottingal, Siebrand Mazeland and other
 * contributors. See CREDITS for a list.
 *
 * UniversalLanguageSelector is dual licensed GPLv2 or later and MIT. You don't
 * have to do anything special to choose one license or the other and you don't
 * have to notify anyone which license you are using. You are free to use
 * UniversalLanguageSelector in commercial projects as long as the copyright
 * header is left intact. See files GPL-LICENSE and MIT-LICENSE for details.
 *
 * @licence GNU General Public Licence 2.0 or later
 * @licence MIT License
 */
'use strict';

// ---
//
// The following functions and data are used time how long the page is visible before the user
// changed their interface language (see `interfaceLanguageChange()`).
//
// See also Stephane Bisson's implementation in WikimediaEvents/ext.wikimediaEvents/InukaPageView.js
// and associated collaborative design/discussion at https://gerrit.wikimedia.org/r/c/mediawiki/extensions/WikimediaEvents/+/551259.

var startedAt = mw.now(),
	hiddenAt = null,
	timeHidden = 0;

function onHide() {
	if ( !hiddenAt ) {
		hiddenAt = mw.now();
	}
}

function onShow() {
	if ( hiddenAt ) {
		timeHidden += mw.now() - hiddenAt;
		hiddenAt = null;
	}
}

// ---

var skin = mw.config.get( 'skin' ),
	skinVersion;

/**
 * @return {string|null} If the user is using the Vector skin, then `'legacy'` or `'latest'`;
 *  `null` otherwise
 */
function getSkinVersion() {
	if ( skinVersion === undefined ) {
		if ( [ 'vector', 'vector-2022' ].indexOf( String( skin ) ) > -1 ) {
			skinVersion = document.body.classList.contains( 'skin-vector-legacy' ) ? 'legacy' : 'latest';
		} else {
			skinVersion = null;
		}
	}

	return skinVersion;
}

/**
 * Try to emit an EventLogging event with schema 'UniversalLanguageSelector'.
 *
 * If EventLogging is not installed, this simply does nothing.
 *
 * @param {Object} event Event action and optional fields
 */
function log( event ) {
	event = $.extend( {

		// Note well that the version and token properties _could_ be removed as they've both been
		// superseded: version has been superseded by the move to the Modern Event Platform; and
		// token has been superseded by the use of the web_session_id property from the
		// web_identifiers fragment below. As of 2021/03/22, however, they can't be removed as
		// backwards incompatible changes may cause issues with the Hive ingestion process (see
		// the discussion on https://gerrit.wikimedia.org/r/c/schemas/event/secondary/+/668743 for
		// detail).
		version: 3,
		token: '',

		// ---

		contentLanguage: mw.config.get( 'wgContentLanguage' ),
		interfaceLanguage: mw.config.get( 'wgUserLanguage' ),

		// The following properties were added in https://phabricator.wikimedia.org/T275766.

		// For detail about the web_session_id property, see
		// https://schema.wikimedia.org/repositories/secondary/jsonschema/fragment/analytics/web_identifiers/current.yaml.
		web_session_id: mw.user.sessionId(), // eslint-disable-line camelcase

		isAnon: mw.user.isAnon(),

		skin: skin,
		skinVersion: getSkinVersion()
	}, event );

	var userEditBucket = mw.config.get( 'wgUserEditCountBucket' );
	if ( userEditBucket ) {
		event.userEditBucket = userEditBucket;
	}

	mw.track( 'event.UniversalLanguageSelector', event );
}

/**
 * Log language settings open
 *
 * @param {string} context Where it was opened from
 */
function ulsSettingsOpen( context ) {
	log( {
		action: 'settings-open',
		context: context
	} );
}

/**
 * Log when the compact language links treatment is opened.
 *
 * @param {jQuery} $trigger The element that triggered the opening of the compact language links
 *  dialog
 */
function ulsCompactLanguageLinksOpen( $trigger ) {
	var context = 'other';
	var isVector = [ 'vector', 'vector-2022' ].indexOf( String( skin ) ) > -1;
	if ( isVector && $trigger.is( '#p-lang-btn-checkbox' ) ) {
		context = 'header';
	} else if ( isVector && $trigger.is( '#p-lang-btn-sticky-header' ) ) {
		context = 'sticky-header';
	}

	log( {
		action: 'compact-language-links-open',
		context: context
	} );
}

/**
 * Log language revert
 */
function ulsLanguageRevert() {
	log( { action: 'ui-lang-revert' } );
}

/**
 * Log IME disabling
 *
 * @param {string} context Where the setting was changed.
 */
function disableIME( context ) {
	log( { action: 'ime-disable', context: context } );
}

/**
 * Log IME enabling
 *
 * @param {string} context Where the setting was changed.
 */
function enableIME( context ) {
	log( { action: 'ime-enable', context: context } );
}

/**
 * Log IME change
 *
 * @param {string} inputMethod
 */
function changeIME( inputMethod ) {
	log( {
		action: 'ime-change',
		inputMethod: inputMethod
	} );
}

/**
 * Log login link click in display settings.
 */
function loginClick() {
	log( { action: 'login-click' } );
}

/**
 * Log when "More languages" item in IME menu is clicked.
 */
function imeMoreLanguages() {
	log( {
		action: 'more-languages-access',
		context: 'ime'
	} );
}

/**
 * Log interface language change
 *
 * @param {string} language language code
 * @param {string} [source] symbolic name for source
 */
function interfaceLanguageChange( language, source ) {
	var logParams = {
		action: 'language-change',
		context: source || 'interface',
		selectedInterfaceLanguage: language,

		// The number of milliseconds that the page was visible before the user changed their
		// interface language.
		//
		// Since `mw.now()` is used, this could be a floating-point value with microsecond
		// precision.
		timeToChangeLanguage: mw.now() - startedAt - timeHidden
	};

	log( logParams );
}

/**
 * More languages in display settings is clicked
 */
function interfaceMoreLanguages() {
	log( {
		action: 'more-languages-access',
		context: 'interface'
	} );
}

/**
 * Log font preference changes
 *
 * @param {string} context Either 'interface' or 'content'
 * @param {string} language
 * @param {string} font
 */
function fontChange( context, language, font ) {
	var logParams = {
		action: 'font-change',
		context: context
	};

	if ( context === 'interface' ) {
		logParams.interfaceFont = font;
		logParams.selectedInterfaceLanguage = language;
	} else {
		logParams.contentFont = font;
	}

	log( logParams );
}

/**
 * Log webfonts disabling
 *
 * @param {string} context Where the setting was changed.
 */
function disableWebfonts( context ) {
	log( { action: 'webfonts-disable', context: context } );
}

/**
 * Log webfonts enabling
 *
 * @param {string} context Where the setting was changed.
 */
function enableWebfonts( context ) {
	log( { action: 'webfonts-enable', context: context } );
}

/**
 * Log search strings which produce no search results.
 *
 * @param {jQuery.event} event The original event
 * @param {Object} data Information about the failed search
 */
function noSearchResults( event, data ) {
	log( {
		action: 'no-search-results',
		context: data.query,
		ulsPurpose: data.ulsPurpose,
		title: mw.config.get( 'wgPageName' )
	} );
}

/**
 * Start listening for event logging
 */
function listen() {
	// Register handlers for event logging triggers
	mw.hook( 'mw.uls.settings.open' ).add( ulsSettingsOpen );
	mw.hook( 'mw.uls.compact_language_links.open' ).add( ulsCompactLanguageLinksOpen );
	mw.hook( 'mw.uls.language.revert' ).add( ulsLanguageRevert );
	mw.hook( 'mw.uls.ime.enable' ).add( enableIME );
	mw.hook( 'mw.uls.ime.disable' ).add( disableIME );
	mw.hook( 'mw.uls.ime.change' ).add( changeIME );
	mw.hook( 'mw.uls.login.click' ).add( loginClick );
	mw.hook( 'mw.uls.ime.morelanguages' ).add( imeMoreLanguages );
	mw.hook( 'mw.uls.interface.morelanguages' ).add( interfaceMoreLanguages );
	mw.hook( 'mw.uls.interface.language.change' ).add( interfaceLanguageChange );
	mw.hook( 'mw.uls.font.change' ).add( fontChange );
	mw.hook( 'mw.uls.webfonts.enable' ).add( enableWebfonts );
	mw.hook( 'mw.uls.webfonts.disable' ).add( disableWebfonts );

	$( document.body ).on(
		'noresults.uls',
		'.uls-menu .uls-languagefilter',
		noSearchResults
	);

	// Time how long the page is visible.
	if ( document.hidden ) {
		onHide();
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			onHide();
		} else {
			onShow();
		}
	} );

	// Wait for DOMContentLoaded to ensure #p-lang is present.
	$( function () {
		// Log language switching event in legacy sidebar for modern Vector.
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '.mw-workspace-container #p-lang' ).on( 'click', '.interlanguage-link-target', function () {
			mw.hook( 'mw.uls.interface.language.change' ).fire(
				this.attributes.getNamedItem( 'hreflang' ).value,
				'languages-list'
			);
		} );
	} );
}

listen();
