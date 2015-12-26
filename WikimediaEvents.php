<?php
/**
 * WikimediaEvents extension
 *
 * @ingroup Extensions
 *
 * @author Ori Livneh <ori@wikimedia.org>
 * @author Matthew Flaschen <mflaschen@wikimedia.org>
 * @author Benny Situ <bsitu@wikimedia.org>
 *
 * @license GPL v2 or later
 * @version 1.0
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'WikimediaEvents' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['WikimediaEvents'] = __DIR__ . '/i18n';
	/*wfWarn(
		'Deprecated PHP entry point used for FooBar extension. Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);*/
	return;
} else {
	die( 'This version of the WikimediaEvents extension requires MediaWiki 1.25+' );
}