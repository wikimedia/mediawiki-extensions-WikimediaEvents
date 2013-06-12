<?php
/**
 * Campaigns extension
 *
 * @ingroup Extensions
 *
 * @author Ori Livneh <ori@wikimedia.org>
 * @author Matthew Flaschen <mflaschen@wikimedia.org>
 *
 * @license GPL v2 or later
 * @version 1.0
 */

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'CoreEvents',
	'version' => '1.0',
	'url' => 'https://www.mediawiki.org/wiki/Extension:CoreEvents',
	'author' => array(
		'Matthew Flaschen',
		'Ori Livneh',
	),
	'descriptionmsg' => 'coreevents-desc',
);


// Messages

$wgExtensionMessagesFiles['CoreEvents'] = __DIR__ . '/CoreEvents.i18n.php';

// Hooks

/**
 * Log server-side event on successful page edit.
 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
 * @see https://meta.wikimedia.org/wiki/Schema:PageContentSaveComplete
 */
// Imported from EventLogging extension
$wgHooks[ 'PageContentSaveComplete' ][] = function ( $article, $user, $content, $summary,
	$isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId ) {

	if ( $revision ) {
		$event = array( 'revisionId' => $revision->getId() );
		if ( isset( $_SERVER[ 'HTTP_USER_AGENT' ] ) ) {
			$event[ 'userAgent' ] = $_SERVER[ 'HTTP_USER_AGENT' ];
		}
		efLogServerSideEvent( 'PageContentSaveComplete', 5303086, $event );
	}
	return true;
};
