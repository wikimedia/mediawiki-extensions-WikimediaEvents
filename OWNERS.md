# Code ownership for instruments in WikimediaEvents

The code in this repository is loaded globally for all users of Wikipedia and other public Wikimedia
Foundation wikis. Including for all page types, namespaces, skins, and devices.

Shipping code comes at a cost. It is important that instruments leave a trace to contact their owners
so that, if optimisations are proposed, there is a way to contact them for code review, and also to
routinely evaluate whether gathered data is still actively being used and providing value to free up
budget for others to deploy instruments.

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

## Search quality (Test Kitchen)

* Since: June 2026
* Files: ext.wikimediaEvents/searchSatisfaction/searchQuality.js
* Contact: https://www.mediawiki.org/wiki/Wikimedia_Search_Platform

Test Kitchen proof-of-concept for the zero-results rate and clicked result position. Re-uses
the same core search signals as searchSatisfaction.js but emits to the `search-quality-2026-06`
instrument. Unlike searchSatisfaction.js it loads on all skins (including mobile/Minerva).

## Reading depth

* Since: November 2021
* Files: readingDepth.js
* Contact: Reader Experience

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
* Contact: Reader Experience

Details at <https://phabricator.wikimedia.org/T220016>.


## Search recommendations A/B test

* Since: January 2025
* Suggested Removal: March 2025
* Folders: ext.wikimediaEvents/searchRecommendations
* Contact: Reader Experience

A schema for evaluating the search recommendations
experiment A/B test (T378094)

Details at <https://phabricator.wikimedia.org/T383611>.

## CommonJS for Web

* Since: May 2023
* Files: webCommon.js
* Contact: Reader Experience

Details at <https://phabricator.wikimedia.org/T335309>.

## Accessibility Settings for Web

* Since: September 2023
* Files: webAccessibilitySettings.js
* Contact: Reader Experience

Details at <https://phabricator.wikimedia.org/T346106>.

## Client Error Logging

* Since: February 2020
* Files: clientError.js
* Contact: Experiment Platform

Details at <https://phabricator.wikimedia.org/T235189>.

## Session Tick

* Since: June 2020
* Files: sessionTick.js
* Contact: Experiment Platform

Details at <https://phabricator.wikimedia.org/T248987>.

## Universal Language Selector

* Since: March 2021
* Files: universalLanguageSelector.js
* Contact: Language and Product Localization, Reader Experience

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
* Files: editAttemptStep.js, editingSessionService.js
* Contact: Editing

Previously maintained in VisualEditor and other extensions since 2014.
Moved here in <https://phabricator.wikimedia.org/T332438>.

## Data Platform Engineering's Bot Detection

* Since: January 2026
* Files: pageVisitBotDetection.js
* Contact: Data Platform Engineering

## Logged-in Reader Retention

* Since: March 2026
* Files: readerRetentionAA/loggedInReaderRetention.js
* Contact: Reader Experience

An experiment to measure logged-in reader retention. This experiment may be run monthly and so
shouldn't be removed.

## Logged-out Reader Retention

* Since: March 2026
* Files: readerRetentionAA/loggedOutReaderRetention.js
* Contact: Experiment Platform

An experiment to measure logged-out reader retention. This experiment is expected to be run monthly
and so shouldn't be removed.

## Attribution Research

* Since: March 2026
* Files: attributionResearch.js
* Contact: Data Engineering

More details at <https://phabricator.wikimedia.org/T417050>

## Active Reader Baseline

* Since: March 2026
* Files: activeReaderBaseline.js
* Contact: Data Engineering

More details at <https://phabricator.wikimedia.org/T420621>

## Edit Saved A/A Tests

* Since: August 2026
* Files: testKitchen/editSavedAA.js
* Contact: Experiment Platform

# Code ownership for other code in WikimediaEvents

## Temporary account instrumentation

* Since: October 2024
* Files: TemporaryAccountsInstrumentation.php, PeriodicMetrics/*
* Contact: Trust & Safety Product

More details at <https://phabricator.wikimedia.org/T357763>

## Special:CreateAccount instrumentation

* Since: July 2025
* Folders: ext.wikimediaEvents/specialCreateAccount, ext.wikimediaEvents/accountCreation
* Contact: Growth

More details at <https://phabricator.wikimedia.org/T394744>

## hCaptcha.js

* Since: November 2025
* Files: ext.wikimediaEvents/hCaptcha.js
* Contact: Product Safety and Integrity

## Experiment Platform Test Kitchen Standardized Instruments

* Since: January 2026
* Modules: ext.wikimediaEvents.testKitchen
* Contact: Experiment Platform

A collection of standardized instruments, including:

1. Click Through Rate (CTR)

## Baseline metrics on Reading List

* Since: March 2026
* Suggested Removal: Evaluate in June 2027
* Files: ext.wikimediaEvents/readingListBaseline.js
* Contact: Reader Experience
More details at <https://phabricator.wikimedia.org/T414368>.

## Page visits for experiments

* Since: July 2026
* Files: ext.wikimediaEvents/anyPageVisit.js
* Contact: Experiment Platform

## Saved edits for experiments

* Since: March 2026
* Files: ext.wikimediaEvents/editSaved.js
* Contact: Editing

## External links instrumentation

* Since: March 2026
* Files: ext.wikimediaEvents/externalLinks.js
* Contact: Product Safety and Integrity

## Suggestion mode

* Since: April 2026
* Files: ext.wikimediaEvents/suggestionMode.js
* Contact: Editing

## Email confirmation banner instrumentation

* Since: June 2026
* Modules: ext.wikimediaEvents.emailConfirmationBanner
* Files: ext.wikimediaEvents/emailConfirmationBanner/emailConfirmationBanner.js, includes/Services/EmailConfirmationBannerInstrumentLogger.php
* Contact: Product Safety and Integrity

## Early Onboarding

* Since: April 2026
* Files: ext.wikimediaEvents/earlyOnboarding.js
* Contact: Contributor Growth (and Reader Experience as a stakeholder)
