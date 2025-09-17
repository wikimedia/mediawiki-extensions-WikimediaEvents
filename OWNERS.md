# Code ownership for instruments in WikimediaEvents

The code in this repository is loaded globally for all users of Wikipedia and other public Wikimedia
Foundation wikis. Including for all page types, namespaces, skins, and devices.

Shipping code comes at a cost. It is important that instruments leave a trace to contact their owners
so that, if optimisations are proposed, there is a way to contact them for code review, and also to
routinely evaluate whether gathered data is still actively being used and providing value to free up
budget for other teams to deploy instruments.

Instrument owners can document the ownership of their instruments by ResourceLoader module, folder,
or file(s). The sections below give examples of all three. Note well that files named `"index.js"`
and non-JavaScript files, e.g. `"config.json"`, is not required. Therefore, instrument owners
should take care when naming and organizing their instrumentation files.

## mw-js-deprecate

* Since: March 2014
* Files: deprecate.js
* Contact: https://wikitech.wikimedia.org/wiki/MediaWiki_Engineering

Powers <https://grafana.wikimedia.org/d/000000037/mw-js-deprecate>.

## StatsD JavaScript

* Since: Dec 2014.
* Files: statsd.js
* Contact: https://wikitech.wikimedia.org/wiki/MediaWiki_Engineering

Handles the ResourceLoader `mw.track()` topics for `counter.*`, `timing.*`, and `stats.*`.
Prometheus support <https://phabricator.wikimedia.org/T355837>.
Documentation at <https://wikitech.wikimedia.org/wiki/Performance.wikimedia.org/Runbook#statsv>.

## Search satisfaction

* Since: October 2015
* Folders: ext.wikimediaEvents/searchSatisfaction
* Contact: https://www.mediawiki.org/wiki/Wikimedia_Search_Platform

## Reading depth

* Since: November 2021
* Files: readingDepth.js
* Contact: https://www.mediawiki.org/wiki/Readers/Web/Team

Details at <https://phabricator.wikimedia.org/T294777>.

## Wikibase

* Since: July 2018
* Modules: ext.wikimediaEvents.wikibase
* Contact: Search Platform

Details at <https://phabricator.wikimedia.org/T196186>.

## Network Probe

* Since: April 2023
* Files: networkProbe.js
* Modules: ext.wikimediaEvents.networkprobe
* Contact: Infrastructure Foundations

Details at <https://phabricator.wikimedia.org/T332024>.

## Click-tracking for Vector and Minerva

* Since: July 2019
* Folders: ext.wikimediaEvents/clickTracking
* Contact: Readers Web

Details at <https://phabricator.wikimedia.org/T220016>.


## Search recommendations A/B test

* Since: January 2025
* Suggested Removal: March 2025
* Folders: ext.wikimediaEvents/searchRecommendations
* Contact: Web Team

A schema for evaluating the search recommendations
experiment A/B test (T378094)

Details at <https://phabricator.wikimedia.org/T383611>.

## ReadingList A/B test

* Since: October 2025
* Suggested Removal: November 2025
* Folders: ext.wikimediaEvents/xLab/readingListAB.js
* Contact: Reader Experience team

Instrumentation for evaluating the impact of Reading List feature.

Details at <https://phabricator.wikimedia.org/T3975>

## CommonJS for Web

* Since: May 2023
* Files: webCommon.js
* Contact: Readers Web

Details at <https://phabricator.wikimedia.org/T335309>.

## Accessibility Settings for Web

* Since: September 2023
* Files: webAccessibilitySettings.js
* Contact: Readers Web

Details at <https://phabricator.wikimedia.org/T346106>.

## Scroll-tracking for Vector

* Since: October 2021
* Files: webUIScroll.js
* Contact: Readers Web

Details at <https://phabricator.wikimedia.org/T292586>.

## Client Error Logging

* Since: February 2020
* Files: clientError.js
* Contact: Data Products

Details at <https://phabricator.wikimedia.org/T235189>.

## Session Tick

* Since: June 2020
* Files: sessionTick.js
* Contact: Data Products

Details at <https://phabricator.wikimedia.org/T248987>.

## Session Length Mixin

* Since: November 2024
* Folders: ext.wikimediaEvents/sessionLength
* Contact: Web team

Details at <https://phabricator.wikimedia.org/T378072>.

## Universal Language Selector

* Since: March 2021
* Files: universalLanguageSelector.js
* Contact: Language and Translation, Readers Web

Migrated from the UniversalLanguageSwitcher extension. Details at
<https://phabricator.wikimedia.org/T275894>.

## Select PHP versions for the backend

* Since: August 2022
* Files: phpEngine.js
* Contact: SRE serviceops

More details at <https://phabricator.wikimedia.org/T311388>

## Blocked edit attempts

* Since: September 2022
* Files: blockedEdit.js
* Contact: Editing

## EditAttemptStep and VisualEditorFeatureUse event logging

* Since: March 2023
* Files: editAttemptStep.js
* Contact: Editing

Previously maintained in VisualEditor and other extensions since 2014.
Moved here in <https://phabricator.wikimedia.org/T332438>.

## Experiment Platform Synthetic A/A Instrumentation

* Since: May 2025
* Files: xLab/pageVisit.js, XLab/PageVisitInstrument.php
* Contact: Experiment Platform

## Web Growth Logged Out Retention A/A Instrumentation

* Since: July 2025
* Files: xLab/loggedOutRetentionVisit.js
* Contact: Web Growth

## MinT for Wiki Readers Synthetic A/A test

* Since: Aug 2025
* Files: xLab/mintReaderPageVisit.js
* Contact: Language and Product Localization

# Code ownership for other code in WikimediaEvents

## Temporary account instrumentation

* Since: October 2024
* Files: TemporaryAccountsInstrumentation.php, PeriodicMetrics/*
* Contact: Trust & Safety Product Team

More details at <https://phabricator.wikimedia.org/T357763>

## Special:CreateAccount instrumentation

* Since: July 2025
* Folders: ext.wikimediaEvents.createAccount
* Contact: Growth Team

More details at <https://phabricator.wikimedia.org/T394744>

## Experiment Platform Standardized Instruments

* Since: January 2025
* Modules: ext.wikimediaEvents.xLab
* Contact: Experiment Platform

A collection of standardized instruments, including:

1. Click Through Rate (CTR)
