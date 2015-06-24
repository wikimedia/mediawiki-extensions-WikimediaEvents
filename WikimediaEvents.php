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
	'version' => '1.1.0',
	'url' => 'https://www.mediawiki.org/wiki/Extension:WikimediaEvents',
	'author' => array(
		'Matthew Flaschen',
		'Ori Livneh',
		'Benny Situ',
	),
	'descriptionmsg' => 'wikimediaevents-desc',
	'license-name' => 'GPL-2.0+',
);

// Configuration

/** @var int|bool: Logs once per this many requests. */
$wgHttpsFeatureDetectionSamplingFactor = 1000;

/**
 * @var bool|string: Full URI or false if not set.
 * Data is logged to this end point as key-value pairs in the query
 * string. Must not contain a query string.
 *
 * @example string: '//log.example.org/statsd'
 */
$wgWMEStatsdBaseUri = false;

// Messages

$wgMessagesDirs['WikimediaEvents'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['WikimediaEvents'] = __DIR__ . '/WikimediaEvents.i18n.php';

// Modules

$wgResourceModules += array(
	'schema.TimingData' => array(
		'class'  => 'ResourceLoaderSchemaModule',
		'schema' => 'TimingData',
		'revision' => 7254808,
	),
	'schema.DeprecatedUsage' => array(
		'class'  => 'ResourceLoaderSchemaModule',
		'schema' => 'DeprecatedUsage',
		'revision' => 7906187,
	),
	'schema.ModuleLoadFailure' => array(
		'class' => 'ResourceLoaderSchemaModule',
		'schema' => 'ModuleLoadFailure',
		'revision' => 12407847,
	),
	'schema.Edit' => array(
		'class' => 'ResourceLoaderSchemaModule',
		'schema' => 'Edit',
		'revision' => 11448630,
	),
	'ext.wikimediaEvents' => array(
		// Loaded globally for all users (including logged-out)
		// Don't remove if empty!
		'scripts'       => array(
			'ext.wikimediaEvents.resourceloader.js',
		),
		'localBasePath' => __DIR__ . '/modules',
		'remoteExtPath' => 'WikimediaEvents/modules',
		'targets' => array( 'desktop', 'mobile' ),
	),
	'ext.wikimediaEvents.loggedin' => array(
		// Loaded globally for all logged-in users
		// Don't remove if empty!
		'scripts'       => array(
			'ext.wikimediaEvents.deprecate.js',
		),
		'localBasePath' => __DIR__ . '/modules',
		'remoteExtPath' => 'WikimediaEvents/modules',
		'targets' => array( 'desktop', 'mobile' ),
	),
	'schema.HttpsSupport' => array(
		'class'    => 'ResourceLoaderSchemaModule',
		'schema'   => 'HttpsSupport',
		'revision' => 11518527,
	),
	'ext.wikimediaEvents.statsd' => array(
		'scripts'       => array(
			'ext.wikimediaEvents.statsd.js',
			'ext.wikimediaEvents.httpsSupport.js',
		),
		'localBasePath' => __DIR__ . '/modules',
		'remoteExtPath' => 'WikimediaEvents/modules',
		'targets'       => array( 'desktop', 'mobile' ),
	),
);

// Autoloader

$wgAutoloadClasses += array(
	'WikimediaEventsHooks' => __DIR__ . '/WikimediaEventsHooks.php',
);

// Hooks

$wgHooks['BeforePageDisplay'][] = 'WikimediaEventsHooks::onBeforePageDisplay';
$wgHooks['PageContentSaveComplete'][] = 'WikimediaEventsHooks::onPageContentSaveComplete';
$wgHooks['UserSaveOptions'][] = 'WikimediaEventsHooks::onUserSaveOptions';
$wgHooks['ArticleDeleteComplete'][] = 'WikimediaEventsHooks::onArticleDeleteComplete';
$wgHooks['ArticleUndelete'][] = 'WikimediaEventsHooks::onArticleUndelete';
$wgHooks['TitleMoveComplete'][] = 'WikimediaEventsHooks::onTitleMoveComplete';
$wgHooks['PageContentInsertComplete'][] = 'WikimediaEventsHooks::onPageContentInsertComplete';
$wgHooks['EditPageBeforeConflictDiff'][] = 'WikimediaEventsHooks::onEditPageBeforeConflictDiff';
$wgHooks['MakeGlobalVariablesScript'][] = 'WikimediaEventsHooks::onMakeGlobalVariablesScript';
$wgHooks['ResourceLoaderGetConfigVars'][] = 'WikimediaEventsHooks::onResourceLoaderGetConfigVars';
$wgHooks['ListDefinedTags'][] = 'WikimediaEventsHooks::onListDefinedTags';
$wgHooks['XAnalyticsSetHeader'][] = 'WikimediaEventsHooks::onXAnalyticsHeader';
