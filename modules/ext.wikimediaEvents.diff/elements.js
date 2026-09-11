/*!
 * The diff-page affordances tracked by the diff-health-metrics instrument (T434795).
 *
 * One entry per row of the instrumentation spec. The friendly names are the spec's own and
 * must not be reworded: analysts filter on element_friendly_name.
 *
 * Several affordances are rendered in more than one place, so each entry maps a short
 * element_id to a selector. Registering every location is deliberate rather than trying to
 * work out which one the reader can see, because visibility here is driven by media queries
 * rather than by skin and cannot be decided up front.
 *
 * Clicks are tracked at every location, and the element_id says which one the reader used.
 * Impressions are not: impressions.js sends at most one for each affordance, for the first
 * location that is really visible, because a pageview gives the reader one opportunity to
 * click an affordance however many times it is drawn. An impression's element_id therefore
 * names that first visible copy, and must not be joined to a click's element_id.
 *
 * Notes on the trickier ones:
 *
 * - The #mw-diff-otitle* and #mw-diff-ntitle* ids are the only discriminators between the
 *   prior (left) and new (right) revision headers, so every in-header selector is scoped to
 *   one of them.
 * - DifferenceEngine::getMobileFooter() emits a second copy of the username, rollback, undo
 *   and Thanks inside .mw-diff-mobile-footer. MinervaNeue hides the header copies at mobile
 *   widths -- along with the whole .diff-otitle column -- and shows the footer instead, so
 *   the prior-revision rows have no mobile equivalent by design.
 * - "Revision as of ..." is the bare anchor inside the header's <strong>. The
 *   .mw-diff-timestamp span next to it is empty; it is a machine-readable hook, not the link.
 * - Tags are tracked on the .mw-tag-markers container rather than per tag, which is what the
 *   spec asks for ("click on any tag associated with either revision") and avoids positional
 *   selectors over a list whose length varies per revision.
 * - DifferenceEngine renders the patrol link twice, in the new revision's header and again
 *   below the diff, and never in the mobile footer. It is shown only where $wgUseRCPatrol is
 *   on and the reader holds the patrol right over a recent, unpatrolled revision that is not
 *   their own, so its impressions count a much smaller population than the other rows.
 *   Core's patrol.js answers a click over the API and removes the clicked copy, which the
 *   capture-phase click listener runs ahead of.
 * - The diff mode switchers are not here; see diffModeSwitcher.js for why.
 */

module.exports = [
	{
		friendlyName: 'Undo',
		targets: {
			header: '#mw-diff-ntitle1 .mw-diff-undo a',
			mobile_footer: '.mw-diff-mobile-footer .mw-diff-undo a'
		}
	},
	{
		friendlyName: 'Thank',
		targets: {
			header: '#mw-diff-ntitle1 a.mw-thanks-thank-link',
			mobile_footer: '.mw-diff-mobile-footer a.mw-thanks-thank-link'
		}
	},
	{
		friendlyName: 'Rollback',
		targets: {
			header: '#mw-diff-ntitle2 .mw-rollback-link a',
			mobile_footer: '.mw-diff-mobile-footer .mw-rollback-link a'
		}
	},
	{
		friendlyName: 'Change visibility',
		targets: {
			prior_revision: '#mw-diff-otitle3 .mw-revdelundel-link a',
			new_revision: '#mw-diff-ntitle3 .mw-revdelundel-link a'
		}
	},
	{
		friendlyName: 'Next edit',
		targets: {
			header: '#differences-nextlink',
			breadcrumb: '.mw-diff-revision-history-link-next'
		}
	},
	{
		friendlyName: 'Previous edit',
		targets: {
			header: '#differences-prevlink',
			breadcrumb: '.mw-diff-revision-history-link-previous'
		}
	},
	{
		friendlyName: 'View history',
		targets: {
			page_tab: '#ca-history',
			sticky_header: '#ca-history-sticky-header'
		}
	},
	{
		friendlyName: 'Talk',
		targets: {
			page_tab: '#ca-talk'
		}
	},
	{
		friendlyName: 'Edit',
		targets: {
			page_tab: '#ca-edit',
			page_tab_visual: '#ca-ve-edit',
			page_tab_viewsource: '#ca-viewsource',
			prior_revision: '#mw-diff-otitle1 .mw-diff-edit a',
			new_revision: '#mw-diff-ntitle1 .mw-diff-edit a'
		}
	},
	{
		friendlyName: 'Watch',
		targets: {
			// Only one of these two is rendered, depending on the current watch state. Once
			// mediawiki.page.watch.ajax has toggled it the id no longer matches, but clicks
			// are matched against the element itself rather than re-running the selector.
			page_tab: '#ca-watch',
			page_tab_watched: '#ca-unwatch',
			sticky_header: '#ca-watchstar-sticky-header'
		}
	},
	{
		friendlyName: 'Edit username',
		targets: {
			header: '#mw-diff-ntitle2 a.mw-userlink',
			mobile_footer: '.mw-diff-mobile-footer a.mw-userlink'
		}
	},
	{
		friendlyName: 'Prior edit username',
		targets: {
			header: '#mw-diff-otitle2 a.mw-userlink'
		}
	},
	{
		friendlyName: 'Edit talk',
		targets: {
			header: '#mw-diff-ntitle2 .mw-usertoollinks-talk'
		}
	},
	{
		friendlyName: 'Prior edit talk',
		targets: {
			header: '#mw-diff-otitle2 .mw-usertoollinks-talk'
		}
	},
	{
		friendlyName: 'Edit contribs',
		targets: {
			header: '#mw-diff-ntitle2 .mw-usertoollinks-contribs'
		}
	},
	{
		friendlyName: 'Prior edit contribs',
		targets: {
			header: '#mw-diff-otitle2 .mw-usertoollinks-contribs'
		}
	},
	{
		friendlyName: 'Tag',
		targets: {
			prior_revision: '#mw-diff-otitle5 .mw-tag-markers',
			new_revision: '#mw-diff-ntitle5 .mw-tag-markers'
		}
	},
	{
		friendlyName: 'Revision',
		targets: {
			prior_revision: '#mw-diff-otitle1 > strong > a:first-child',
			new_revision: '#mw-diff-ntitle1 > strong > a:first-child'
		}
	},
	{
		friendlyName: 'Interactive browser',
		targets: {
			toggle: '.mw-revslider-toggle-button'
		}
	},
	{
		friendlyName: 'Mark as patrolled',
		targets: {
			// DifferenceEngine renders the patrol link twice: once in the new revision's
			// header after the next-edit link, and once as a redundant copy below the diff.
			header: '#mw-diff-ntitle4 .patrollink a',
			below_diff: '#mw-content-text > .patrollink a'
		}
	}
];
