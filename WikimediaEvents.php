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

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'WikimediaEvents',
	'version' => '1.0',
	'url' => 'https://www.mediawiki.org/wiki/Extension:WikimediaEvents',
	'author' => array(
		'Matthew Flaschen',
		'Ori Livneh',
		'Benny Situ',
	),
	'descriptionmsg' => 'wikimediaevents-desc',
);

// Messages

$wgExtensionMessagesFiles['WikimediaEvents'] = __DIR__ . '/WikimediaEvents.i18n.php';

// Configs

$wgResourceModules += array(
	'schema.TimingData' => array(
		'class'  => 'ResourceLoaderSchemaModule',
		'schema' => 'TimingData',
		'revision' => 7254808,
	),
	'ext.wikimediaEvents.ve' => array(
		'scripts'       => 'ext.wikimediaEvents.ve.js',
		'dependencies'  => 'ext.visualEditor.base',
		'localBasePath' => __DIR__ . '/modules',
		'remoteExtPath' => 'WikimediaEvents/modules',
		'targets' => array( 'desktop', 'mobile' ),
	),
);

$wgVisualEditorPluginModules[] = 'ext.wikimediaEvents.ve';

// Autoloader

$wgAutoloadClasses += array(
	'WikimediaEventsHooks' => __DIR__ . '/WikimediaEventsHooks.php',
);

// Hooks

$wgHooks['PageContentSaveComplete'][] = 'WikimediaEventsHooks::onPageContentSaveComplete';
$wgHooks['UserSaveOptions'][] = 'WikimediaEventsHooks::onUserSaveOptions';
$wgHooks['ArticleDeleteComplete'][] = 'WikimediaEventsHooks::onArticleDeleteComplete';
$wgHooks['TitleMoveComplete'][] = 'WikimediaEventsHooks::onTitleMoveComplete';
$wgHooks['PageContentInsertComplete'][] = 'WikimediaEventsHooks::onPageContentInsertComplete';
