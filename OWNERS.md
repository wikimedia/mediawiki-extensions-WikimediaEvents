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

## WMF-specific event logging

* Since: June 2016
* Files: events.js
* Contact: Product Infrastructure team

Proxies the mw-track topic `wikimedia.event.*` to `event.*`.
Background at <https://phabricator.wikimedia.org/T138659>.

## Reading depth

* Since: February 2017
* Files: readingDepth.js
* Contact: https://www.mediawiki.org/wiki/Readers/Web/Team

Details at <https://phabricator.wikimedia.org/T155639>.

## Citation usage

* Since: June 2018
* Files: citationUsage.js
* Contact: Research

Details at <https://phabricator.wikimedia.org/T191086>

## Wikibase

* Since: July 2018
* Files: completionClicks.js
* Contact: Search Platform

Details at <https://phabricator.wikimedia.org/T196186>.

## Click-tracking for Vector and Minerva

* Since: July 2019
* Files: mobileWebUIActions.js, desktopWebUIActions.js
* Contact: Readers Web"

Details at <https://phabricator.wikimedia.org/T220016>.

## Inuka page views

* Since: February 2020
* Files: InukaPageView.js
* Contact: Inuka team

Details at <https://phabricator.wikimedia.org/T238029>.

## Client Error Logging

* Since: February 2020
* Files: clientError.js
* Contact: Product Infrastructure

Details at <https://phabricator.wikimedia.org/T235189>.
