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

/**
 * @var bool|string: Full URI or false if not set.
 * Data is logged to this end point as key-value pairs in the query
 * string. Must not contain a query string.
 *
 * @example string: '//log.example.org/statsd'
 */
$wgWMEStatsdBaseUri = false;

/**
 * @var bool: Whether geo/maps features specific to large Wikipedias should be tracked
 */
$wgWMETrackGeoFeatures = false;

/**
 * @var bool Whether the completion suggestion experiment is enabled.
 */
$wgWMEEnableCompletionExperiment = false;

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
		'revision' => 13457736,
	),
	'schema.DidYouMean' => array(
		'class' => 'ResourceLoaderSchemaModule',
		'schema' => 'DidYouMean',
		'revision' => 13800499,
	),
	'schema.CompletionSuggestions' => array(
		'class' => 'ResourceLoaderSchemaModule',
		'schema' => 'CompletionSuggestions',
		'revision' => 13630018,
	),
	'schema.TestSearchSatisfaction2' => array(
		'class'    => 'ResourceLoaderSchemaModule',
		'schema'   => 'TestSearchSatisfaction2',
		'revision' => 14098806,
	),
	'schema.GeoFeatures' => array(
		'class'    => 'ResourceLoaderSchemaModule',
		'schema'   => 'GeoFeatures',
		'revision' => 12914994,
	),
	'ext.wikimediaEvents' => array(
		// Loaded globally for all users (including logged-out)
		// Don't remove if empty!
		'scripts'       => array(
			'ext.wikimediaEvents.resourceloader.js',
			'ext.wikimediaEvents.searchSuggest.js',
			'ext.wikimediaEvents.statsd.js',
			'ext.wikimediaEvents.search.js',
		),
		'dependencies' => array(
			'mediawiki.user', // needed by searchSuggest.js
			'mediawiki.Uri', // needed by search.js
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
		'dependencies' => array(
		),
	),
	'ext.wikimediaEvents.didyoumean' => array(
		'scripts' => array(
			'ext.wikimediaEvents.didyoumean.js',
		),
		'localBasePath' => __DIR__ . '/modules',
		'remoteExtPath' => 'WikimediaEvents/modules',
		'targets' => array( 'desktop' ),
		'dependencies' => 'mediawiki.Uri',
	),
	'ext.wikimediaEvents.geoFeatures' => array(
		'scripts'       => array(
			'ext.wikimediaEvents.geoFeatures.js',
		),
		'localBasePath' => __DIR__ . '/modules',
		'remoteExtPath' => 'WikimediaEvents/modules',
		'targets'       => array( 'desktop', 'mobile' ),
		'dependencies'  => array( 'schema.GeoFeatures' ),
	),
);

// Autoloader

$wgAutoloadClasses += array(
	'WikimediaEventsHooks' => __DIR__ . '/WikimediaEventsHooks.php',
	'AuthManagerStatsdHandler' => __DIR__ . '/includes/AuthManagerStatsdHandler.php',
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
$wgHooks['ResourceLoaderGetConfigVars'][] = 'WikimediaEventsHooks::onResourceLoaderGetConfigVars';
$wgHooks['ListDefinedTags'][] = 'WikimediaEventsHooks::onListDefinedTags';
$wgHooks['XAnalyticsSetHeader'][] = 'WikimediaEventsHooks::onXAnalyticsHeader';
$wgHooks['SpecialSearchResults'][] = 'WikimediaEventsHooks::onSpecialSearchResults';
