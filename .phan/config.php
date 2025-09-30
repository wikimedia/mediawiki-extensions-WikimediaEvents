<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'stubs',
		'../../extensions/cldr',
		'../../extensions/ConfirmEdit',
		'../../extensions/EmailAuth',
		'../../extensions/EventBus',
		'../../extensions/EventLogging',
		'../../extensions/IPReputation',
		'../../extensions/MobileFrontend',
		'../../extensions/LoginNotify',
		'../../extensions/GlobalPreferences',
		'../../extensions/GrowthExperiments',
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/BetaFeatures',
		'../../extensions/CheckUser',
		'../../extensions/FlaggedRevs',
		'../../extensions/GlobalBlocking',
		'../../extensions/OATHAuth',
		'../../extensions/MetricsPlatform',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'stubs',
		'../../extensions/cldr',
		'../../extensions/ConfirmEdit',
		'../../extensions/EmailAuth',
		'../../extensions/EventBus',
		'../../extensions/EventLogging',
		'../../extensions/IPReputation',
		'../../extensions/MobileFrontend',
		'../../extensions/LoginNotify',
		'../../extensions/GlobalPreferences',
		'../../extensions/GrowthExperiments',
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/BetaFeatures',
		'../../extensions/CheckUser',
		'../../extensions/FlaggedRevs',
		'../../extensions/GlobalBlocking',
		'../../extensions/OATHAuth',
		'../../extensions/MetricsPlatform',
	]
);

return $cfg;
