/*!
 * Emits one impression per tracked affordance, the first time the reader can actually see it.
 *
 * ClickThroughRateInstrument's own impression cannot be used for a diff page.
 * It sends as soon as its IntersectionObserver reports on an element, without checking
 * isIntersecting, so an element that is in the DOM but never visible earns an impression it
 * was never given. A diff has several of those: DifferenceEngine renders both the desktop
 * header and the mobile footer copy of undo, Thanks, rollback and the username, and CSS
 * decides which one the reader gets, so on desktop the mobile copies alone would roughly
 * double those impression counts.
 *
 * The same affordance appearing in more than one place also has to count once. "Edit" is a
 * page tab, a VisualEditor tab and a link beside each revision, but a pageview offers the
 * reader one opportunity to click Edit, not four.
 *
 * Both would inflate the denominator of every clickthrough rate this instrument exists to
 * measure, so impressions are emitted here instead: at most one per group key, and only once
 * an element in that group is genuinely on screen.
 */

/**
 * Group keys that have already sent an impression.
 *
 * @type {Set<string>}
 */
const sent = new Set();

/**
 * Pending observations, grouped by element. One element can carry more than one key: the
 * visual/wikitext switcher offers two affordances in a single widget.
 *
 * @type {Map<Element, Array<{key: string, sender: Object}>>}
 */
const observed = new Map();

const observer = new IntersectionObserver( ( entries ) => {
	entries.forEach( ( entry ) => {
		if ( !entry.isIntersecting ) {
			return;
		}

		const pending = observed.get( entry.target ) || [];

		observer.unobserve( entry.target );
		observed.delete( entry.target );

		pending.forEach( ( { key, sender } ) => {
			if ( sent.has( key ) ) {
				return;
			}

			sent.add( key );
			sender.send( 'impression', {} );
		} );
	} );
}, {
	// Not the full element: several of these are narrow inline links inside a header that
	// can be clipped at narrow widths, and a link the reader can see and click has earned
	// its impression whether or not every pixel of it is in the viewport.
	threshold: 0
} );

module.exports = {
	/**
	 * @param {Element} element
	 * @param {string} key Affordance identity. At most one impression is sent per key, so
	 *  every element rendering the same affordance should share one.
	 * @param {Object} sender An event sender, as returned by index.js's newSender()
	 */
	observe: function ( element, key, sender ) {
		if ( sent.has( key ) ) {
			return;
		}

		const pending = observed.get( element );

		if ( pending ) {
			if ( !pending.some( ( entry ) => entry.key === key ) ) {
				pending.push( { key: key, sender: sender } );
			}

			return;
		}

		observed.set( element, [ { key: key, sender: sender } ] );
		observer.observe( element );
	},

	/**
	 * Forget everything, including which affordances have already sent an impression.
	 *
	 * Call this when the diff itself changes rather than on any re-registration: a reader
	 * who drags a RevisionSlider pointer is looking at a different pair of revisions, so
	 * that is a fresh opportunity to act and needs a fresh set of impressions.
	 */
	stop: function () {
		observed.forEach( ( _pending, element ) => observer.unobserve( element ) );
		observed.clear();
		sent.clear();
	}
};

// Exposed for unit testing.
if ( window.QUnit ) {
	module.exports.reset = function () {
		this.stop();
	};
}
