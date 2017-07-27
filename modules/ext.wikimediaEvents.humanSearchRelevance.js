( function ( mw, $, undefined ) {
	'use strict';

	function oneIn( populationSize ) {
		var rand = mw.user.generateRandomSessionId(),
			parsed = parseInt( rand.slice( 0, 13 ), 16 );
		return parsed % populationSize === 0;
	}

	function chooseOne( options ) {
		var rand = mw.user.generateRandomSessionId(),
			parsed = parseInt( rand.slice( 0, 13 ), 16 ),
			step = Math.pow( 2, 52 ) / options.length;
		return options[ Math.floor( parsed / step ) ];
	}

	// Only accept enwiki, NS_MAIN for MVP feasability test
	if ( mw.config.get( 'wgNamespaceNumber' ) !== 0 ||
		mw.config.get( 'wgDBname' ) !== 'enwiki'
	) {
		return;
	}

	// For the MVP we are simply hardcoding the list of queries and articles.
	// If the MVP shows to return data that isn't complete junk this will be
	// revisited, perhaps embedding the desired queries into cached page render
	// or some such. oneIn values are tuned for approximately 1000 impressions
	// per week.
	var config = {
		429700: {
			oneIn: 12,
			queries: [ 'search engine' ]
		},
		1140230: {
			oneIn: 1,
			queries: [ 'sailor soldier tinker spy' ]
		},
		4184791: {
			oneIn: 1,
			queries: [ '10 items or fewer' ]
		},
		28203916: {
			oneIn: 1,
			queries: [ 'block buster' ]
		},
		1692813: {
			oneIn: 1,
			queries: [ 'sailor soldier tinker spy' ]
		},
		4059023: {
			oneIn: 9,
			queries: [ 'search engine' ]
		},
		12432: {
			oneIn: 2,
			queries: [ 'what is a genius iq?' ]
		},
		54255761: {
			oneIn: 1,
			queries: [ 'who is v for vendetta?' ]
		},
		15170457: {
			oneIn: 1,
			queries: [ 'yesterday beetles' ]
		},
		4302959: {
			oneIn: 2,
			queries: [ 'what is a genius iq?' ]
		},
		772896: {
			oneIn: 1,
			queries: [ 'yesterday beetles' ]
		},
		14067873: {
			oneIn: 1,
			queries: [ 'star and stripes' ]
		},
		212645: {
			oneIn: 4,
			queries: [ 'why is a baby goat a kid?' ]
		},
		666918: {
			oneIn: 1,
			queries: [ 'star and stripes' ]
		},
		31840255: {
			oneIn: 1,
			queries: [ 'what is a genius iq?' ]
		},
		73257: {
			oneIn: 3,
			queries: [ 'who is v for vendetta?' ]
		},
		187946: {
			oneIn: 19,
			queries: [ 'search engine' ]
		},
		1891886: {
			oneIn: 9,
			queries: [ 'who is v for vendetta?' ]
		},
		43055: {
			oneIn: 5,
			queries: [ 'block buster' ]
		},
		14705456: {
			oneIn: 1,
			queries: [ 'sailor soldier tinker spy' ]
		},
		308913: {
			oneIn: 1,
			queries: [ 'star and stripes' ]
		},
		4553266: {
			oneIn: 1,
			queries: [ 'who is v for vendetta?' ]
		},
		2848825: {
			oneIn: 1,
			queries: [ 'yesterday beetles' ]
		},
		45144821: {
			oneIn: 1,
			queries: [ '10 items or fewer' ]
		},
		19167553: {
			oneIn: 7,
			queries: [ 'why is a baby goat a kid?' ]
		},
		20412995: {
			oneIn: 1,
			queries: [ '10 items or fewer' ]
		},
		12940960: {
			oneIn: 7,
			queries: [ 'what is a genius iq?' ]
		},
		1163847: {
			oneIn: 1,
			queries: [ 'why is a baby goat a kid?' ]
		},
		5663176: {
			oneIn: 1,
			queries: [ 'why is a baby goat a kid?' ]
		},
		46743209: {
			oneIn: 1,
			queries: [ '10 items or fewer' ]
		},
		19424330: {
			oneIn: 1,
			queries: [ 'sailor soldier tinker spy' ]
		},
		2111074: {
			oneIn: 5,
			queries: [ 'search engine' ]
		},
		33138509: {
			oneIn: 1,
			queries: [ 'what is a genius iq?' ]
		},
		4576465: {
			oneIn: 7,
			queries: [ 'how do flowers bloom?' ]
		},
		6218066: {
			oneIn: 1,
			queries: [ 'yesterday beetles' ]
		},
		8964793: {
			oneIn: 1,
			queries: [ '10 items or fewer' ]
		},
		24202203: {
			oneIn: 1,
			queries: [ 'yesterday beetles' ]
		},
		14064991: {
			oneIn: 1,
			queries: [ 'search engine' ]
		},
		2295010: {
			oneIn: 2,
			queries: [ 'how do flowers bloom?' ]
		},
		672166: {
			oneIn: 1,
			queries: [ 'how do flowers bloom?' ]
		},
		4706150: {
			oneIn: 1,
			queries: [ 'who is v for vendetta?' ]
		},
		11011055: {
			oneIn: 1,
			queries: [ 'how do flowers bloom?' ]
		},
		3818608: {
			oneIn: 1,
			queries: [ 'block buster' ]
		},
		638069: {
			oneIn: 2,
			queries: [ 'sailor soldier tinker spy' ]
		},
		1724918: {
			oneIn: 1,
			queries: [ 'block buster' ]
		},
		168617: {
			oneIn: 7,
			queries: [ 'star and stripes' ]
		},
		12820089: {
			oneIn: 1,
			queries: [ 'how do flowers bloom?' ]
		},
		13823739: {
			oneIn: 1,
			queries: [ 'block buster' ]
		},
		14203818: {
			oneIn: 1,
			queries: [ 'why is a baby goat a kid?' ]
		},
		560511: {
			oneIn: 2,
			queries: [ 'star and stripes' ]
		}
	}[ mw.config.get( 'wgArticleId' ) ];

	// Page is not part of this test
	if ( config === undefined ) {
		return;
	}

	// This page view not chosen for sampling
	if ( !oneIn( config.oneIn ) ) {
		return;
	}

	function askQuestion() {
		mw.loader.using( [
			'oojs-ui-core',
			'mediawiki.notification',
			'ext.wikimediaEvents.humanSearchRel'
		] ).then( function () {
			var notification, originalClose,
				closed = false,
				query = chooseOne( config.queries ),
				question = 'wikimediaevents-humanrel-question-' + chooseOne( [ 'a', 'b', 'c', 'd' ] ),
				logEvent = function ( choice ) {
					if ( !closed ) {
						closed = true;
						notification.close();
					}
					mw.loader.using( [ 'schema.HumanSearchRelevance' ] ).then( function () {
						mw.eventLog.logEvent( 'HumanSearchRelevance', {
							articleId: mw.config.get( 'wgArticleId' ),
							query: query,
							choice: choice,
							question: question,
							mwSessionId: mw.user.sessionId()
						} );
					} );
				},
				/* global OO */
				buttons = new OO.ui.ButtonGroupWidget( {
					items: [ 'yes', 'no', 'unsure' ].map( function ( choice ) {
						return new OO.ui.ButtonWidget( {
							label: mw.message( 'wikimediaevents-humanrel-' + choice ).text()
						} ).connect( {}, {
							click: [ logEvent, choice ]
						} );
					} )
				} ),
				content = $( '<p/>', {
					'class': 'mw-wme-humanrel-question'
				} ).text( mw.message( question, query ) );

			content.append( buttons.$element );
			content.append( $( '<small/>' ).append( $( '<a/>', {
				href: '//wikimediafoundation.org/wiki/Privacy_policy',
				target: '_blank'
			} ).text( 'Privacy Policy' ) ) );

			notification = mw.notification.notify( content, {
				autoHide: true,
				autoHideSeconds: 'long'
			} );

			originalClose = notification.close.bind( notification );
			notification.close = function () {
				// This is certainly fragile and depends on implementation details,
				// but for an MVP not going to refactor anything in notifications.
				// this.isPaused: true when user clicks to dismiss
				// this.isPaused: false when notification auto-hides
				if ( !closed ) {
					closed = true;
					logEvent( this.isPaused ? 'dismiss' : 'timeout' );
				}
				originalClose();
			};
		} );
	}

	// TODO: We probably want to vary this 60s for some AB tests, to see if the quality
	// of human grades varies depending on how long we wait.
	setTimeout( askQuestion, 60 );

}( mediaWiki, jQuery ) );
