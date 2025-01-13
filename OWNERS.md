# Code ownership for instruments in WikimediaEvents

The code in this repository is loaded globally for all users of Wikipedia and other public Wikimedia
Foundation wikis. Including for all page types, namespaces, skins, and devices.

Shipping code comes at a cost. It is important that campaigns leave a trace to contact their owners
so that, if optimisations are proposed, there is a way to contact them for code review, and also to
routinely evaluate whether gathered data is still actively being used and providing value to free up
budget for other teams to deploy campaigns.

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
* Files: searchSatisfaction.js, searchSli.js
* Contact: https://www.mediawiki.org/wiki/Wikimedia_Search_Platform

## Reading depth

* Since: November 2021
* Files: readingDepth.js
* Contact: https://www.mediawiki.org/wiki/Readers/Web/Team

Details at <https://phabricator.wikimedia.org/T294777>.

## Wikibase

* Since: July 2018
* Files: completionClicks.js
* Contact: Search Platform

Details at <https://phabricator.wikimedia.org/T196186>.

## Network Probe

* Since: April 2023
* Files: init.js, probenet.js, recipe.js, networkProbe.js
* Contact: Infrastructure Foundations

Details at <https://phabricator.wikimedia.org/T332024>.

## Click-tracking for Vector and Minerva

* Since: July 2019
* Files: webUIClick.js, utils.js
* Contact: Readers Web

Details at <https://phabricator.wikimedia.org/T220016>.

## A/B Test initialization for Vector

* Since: October 2021
* Files: webABTestEnrollment.js
* Contact: Readers Web

Details at <https://phabricator.wikimedia.org/T292587>.

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

# Code ownership for other code in WikimediaEvents

## Temporary account instrumentation

* Since: October 2024
* Files: TemporaryAccountsInstrumentation.php, PeriodicMetrics/*
* Contact: Trust & Safety Product Team

More details at <https://phabricator.wikimedia.org/T357763>

## Experiment Platform Standardized Instruments

* Since: January 2025
* Files: ClickThroughRateInstrument.js
* Contact: Experiment Platform

A collection of standardized instruments, including:

1. Click Through Rate (CTR)

## Experimentation Lab Tests

* Since: January 2025
* Files: ExLabTest1.js
* Contact: Experiment Platform

These files test the Experimentation Lab end-to-end.
These files are temporary and will be removed soon after being added.

https://phabricator.wikimedia.org/T383801 tracks the removal of `ExLabTest1.js`.
