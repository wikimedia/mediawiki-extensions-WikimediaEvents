/*!
 * Instrumentation for the two diff presentation switchers (T434795, T436254).
 *
 * These four spec rows -- Visual mode, Wikitext mode, Inline on, Inline off -- do not fit the
 * shared ClickThroughRateInstrument component, which binds a fixed friendly name to an
 * element reference taken at registration time:
 *
 * - VisualEditor empties .ve-init-mw-diffPage-diffMode and replaces the server-rendered
 *   button group with a client-side widget, inside its own wikipage.diff handler. Hook
 *   handlers run in registration order, so an element reference taken here may be discarded
 *   before it is ever used. Which button was clicked is therefore resolved at click time from
 *   the button's icon class, which is stable across both markups and does not depend on
 *   position in the DOM.
 * - The inline toggle is a single element that performs two different actions depending on
 *   its current state, so its click cannot carry a fixed friendly name.
 *
 * Impressions go through impressions.js like every other affordance, which also means the
 * inline toggle earns none on MinervaNeue, where it is rendered but hidden.
 */

const diffMode = require( './diffMode.js' );
const impressions = require( './impressions.js' );

const VISUAL_SWITCHER = '.ve-init-mw-diffPage-diffMode';
const INLINE_TOGGLE = '#mw-diffPage-inline-toggle-switch';

// OOUI renders the icon as oo-ui-icon-<name>, for both the PHP ButtonGroupWidget that
// VisualEditor emits server-side and the ButtonSelectWidget that replaces it.
const VISUAL_BUTTON_ICON = '.oo-ui-icon-eye';

const FRIENDLY_NAME_VISUAL = 'Visual mode';
const FRIENDLY_NAME_WIKITEXT = 'Wikitext mode';
const FRIENDLY_NAME_INLINE_ON = 'Inline on';
const FRIENDLY_NAME_INLINE_OFF = 'Inline off';

// The click listener and the toggle subscription are page-lifetime, so they are wired once
// however many times start() is called. They therefore read the state below at send time
// instead of capturing it: RevisionSlider can load a new diff without a page load, and
// start() then runs again with a new sender and a new set of funnel tokens.
let listening = false;
let sender = null;
let funnelEntryTokens = {};
let clickListener = null;

/**
 * One token per friendly name, linking that name's impression to any click that follows, the
 * same way ClickThroughRateInstrument does for the elements it tracks.
 *
 * @param {string} friendlyName
 * @return {string}
 */
function tokenFor( friendlyName ) {
	funnelEntryTokens[ friendlyName ] = funnelEntryTokens[ friendlyName ] ||
		mw.user.generateRandomSessionId();

	return funnelEntryTokens[ friendlyName ];
}

/**
 * @param {string} friendlyName
 * @return {Object} A sender that labels its events with friendlyName
 */
function senderFor( friendlyName ) {
	return {
		send: ( action, interactionData ) => sender.send(
			action,
			Object.assign( {}, interactionData, {
				funnel_entry_token: tokenFor( friendlyName ),
				element_friendly_name: friendlyName
			} )
		)
	};
}

/**
 * @param {string} action
 * @param {string} friendlyName
 */
function send( action, friendlyName ) {
	senderFor( friendlyName ).send( action, {} );
}

/**
 * Instrument the diff mode switchers. Safe to call when neither switcher is rendered, which
 * is the case on MinervaNeue at mobile widths and when wikidiff2 offers no inline format,
 * and safe to call again when the diff changes.
 *
 * @param {Object} eventSender An event sender, as returned by index.js's newSender()
 */
function start( eventSender ) {
	sender = eventSender;
	// A new diff is a new impression, so it gets new tokens. The listeners below read them
	// through tokenFor(), so a click still joins the impression it followed.
	funnelEntryTokens = {};

	const visualSwitcher = document.querySelector( VISUAL_SWITCHER );

	if ( visualSwitcher ) {
		[ FRIENDLY_NAME_VISUAL, FRIENDLY_NAME_WIKITEXT ].forEach( ( friendlyName ) => {
			impressions.observe( visualSwitcher, friendlyName, senderFor( friendlyName ) );
		} );
	}

	const inlineToggle = document.querySelector( INLINE_TOGGLE );

	if ( inlineToggle ) {
		// The toggle's two spec rows are one element, so only the action a click would
		// perform right now is on offer.
		const friendlyName = diffMode.isInline() ?
			FRIENDLY_NAME_INLINE_OFF :
			FRIENDLY_NAME_INLINE_ON;

		impressions.observe( inlineToggle, friendlyName, senderFor( friendlyName ) );
	}

	if ( listening ) {
		return;
	}

	listening = true;

	// Capture phase, because the OOUI widget's own handlers may stop propagation.
	clickListener = ( event ) => {
		const button = event.target.closest( VISUAL_SWITCHER + ' .oo-ui-buttonElement' );

		if ( !button ) {
			return;
		}

		const isVisual = !!button.querySelector( VISUAL_BUTTON_ICON );

		// Send first, so that the event's action_context records the mode the reader was
		// leaving. Where they went is already in the friendly name, so together the two
		// give the from and the to of the switch.
		send( 'click', isVisual ? FRIENDLY_NAME_VISUAL : FRIENDLY_NAME_WIKITEXT );
		diffMode.setVisual( isVisual );
	};

	document.addEventListener( 'click', clickListener, true );

	// The toggle's own change event rather than a click, so that keyboard activation is
	// counted too. The value is the state being switched to.
	diffMode.onInlineChange( ( isInline ) => {
		send( 'click', isInline ? FRIENDLY_NAME_INLINE_ON : FRIENDLY_NAME_INLINE_OFF );
	} );
}

module.exports = {
	start: start
};

// Exposed for unit testing.
if ( window.QUnit ) {
	module.exports.FRIENDLY_NAME_VISUAL = FRIENDLY_NAME_VISUAL;
	module.exports.FRIENDLY_NAME_WIKITEXT = FRIENDLY_NAME_WIKITEXT;
	module.exports.FRIENDLY_NAME_INLINE_ON = FRIENDLY_NAME_INLINE_ON;
	module.exports.FRIENDLY_NAME_INLINE_OFF = FRIENDLY_NAME_INLINE_OFF;
	module.exports.reset = function () {
		// The listener has to go, not just the flag: every listener reads the shared sender
		// above, so one left behind would double-send into the next test.
		if ( clickListener ) {
			document.removeEventListener( 'click', clickListener, true );
			clickListener = null;
		}

		listening = false;
		sender = null;
		funnelEntryTokens = {};
	};
}
