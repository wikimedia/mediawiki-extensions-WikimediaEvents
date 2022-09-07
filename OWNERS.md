# Code ownership for components of WikimediaEvents

The code in this repository is loaded globally for all users of Wikipedia and other public
Wikimedia Foundation wikis. Including for all page types, namespaces, skins, and devices.

Shipping code here comes at a cost, and it is important that campaigns leave a trace
to contact owners. This way, if optimisations are proposed, there is a way to contact
for code review, and to routinely evaluate whether gathered data is still actively being
used and providing value so as to free up budget for other teams to deploy campaigns.

## mw-js-deprecate

* Since: March 2014
* Files: deprecate.js
* Contact: https://www.mediawiki.org/wiki/Wikimedia_Performance_Team

Powers <https://grafana.wikimedia.org/dashboard/db/mw-js-deprecate>.

## StatsD from JavaScript

* Since: Dec 2014.
* Files: statsd.js
* Contact: Analytics team

Handles the mw-track topics for `counter.*`, `timing.*`, and `gauge.*`.
Documentation at <https://wikitech.wikimedia.org/wiki/Graphite#statsv>.

## Search satisfaction

* Since: October 2015
* Files: searchSatisfaction.js
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

## Click-tracking for Vector and Minerva

* Since: July 2019
* Files: mobileWebUIActions.js, desktopWebUIActions.js
* Contact: Readers Web

Details at <https://phabricator.wikimedia.org/T220016>.

## A/B Test initialization for Vector

* Since: October 2021
* Files: webABTestEnrollment.js
* Contact: Readers Web

Details at <https://phabricator.wikimedia.org/T292587>.

## Scroll-tracking for Vector

* Since: October 2021
* Files: webUIScroll.js
* Contact: Readers Web

Details at <https://phabricator.wikimedia.org/T292586>.

## Client Error Logging

* Since: February 2020
* Files: clientError.js
* Contact: Product Infrastructure

Details at <https://phabricator.wikimedia.org/T235189>.

## Session Tick

* Since: June 2020
* Files: sessionTick.js
* Contact: Product Infrastructure

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
