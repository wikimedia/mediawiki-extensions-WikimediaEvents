<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/EventBus',
		'../../extensions/EventLogging',
		'../../extensions/MobileFrontend',
		'../../extensions/GlobalPreferences',
		'../../extensions/GrowthExperiments',
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/BetaFeatures',
		'../../extensions/CheckUser',
		'../../extensions/FlaggedRevs',
		'../../extensions/GlobalBlocking',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/EventBus',
		'../../extensions/EventLogging',
		'../../extensions/MobileFrontend',
		'../../extensions/GlobalPreferences',
		'../../extensions/GrowthExperiments',
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/BetaFeatures',
		'../../extensions/CheckUser',
		'../../extensions/FlaggedRevs',
		'../../extensions/GlobalBlocking',
	]
);

return $cfg;
