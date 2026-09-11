/* eslint-env qunit */
'use strict';

QUnit.module( 'ext.wikimediaEvents.diff/elements', () => {
	const elements = require( 'ext.wikimediaEvents.diff/elements.js' );

	QUnit.test( 'every friendly name is unique', ( assert ) => {
		// Analysts filter on element_friendly_name, and impressions are de-duplicated by it,
		// so two entries sharing a name would silently merge into one affordance.
		const names = elements.map( ( entry ) => entry.friendlyName );

		assert.deepEqual(
			names.filter( ( name, i ) => names.indexOf( name ) !== i ),
			[],
			'no repeated names'
		);
	} );

	QUnit.test( 'every selector is valid and every element_id is filled in', ( assert ) => {
		elements.forEach( ( { friendlyName, targets } ) => {
			const elementIds = Object.keys( targets );

			assert.true( elementIds.length > 0, friendlyName + ' has at least one target' );

			elementIds.forEach( ( elementId ) => {
				const selector = targets[ elementId ];
				const label = friendlyName + ' | ' + elementId;

				// A typo here fails silently in production: index.js skips a selector that
				// matches nothing, and an invalid one would throw inside the forEach.
				try {
					document.querySelector( selector );
					assert.true( true, label + ' parses' );
				} catch ( e ) {
					assert.true( false, label + ' is not a valid selector: ' + selector );
				}
			} );
		} );
	} );

	QUnit.test( 'the two patrol link copies resolve separately', ( assert ) => {
		// The patrol link is the one affordance whose copies are told apart by their
		// ancestors rather than by a distinguishing class, so the selectors are worth
		// pinning down. DifferenceEngine puts one inside #mw-diff-ntitle4 and appends a
		// second, identical copy after the diff.
		/**
		 * @return {jQuery}
		 */
		function newPatrolLink() {
			return $( '<span>' )
				.addClass( 'patrollink' )
				.attr( 'data-mw-interface', '' )
				.append( $( '<a>' ).text( 'Mark as patrolled' ) );
		}

		const $header = newPatrolLink();
		const $belowDiff = newPatrolLink();

		// Stands in for the skin's body-text wrapper. An id selector matches any element
		// carrying the id, so nesting one inside the fixture is enough and leaves QUnit's
		// own fixture element untouched.
		$( '<div>' )
			.attr( 'id', 'mw-content-text' )
			.append(
				$( '<table>' ).addClass( 'diff' ).append(
					$( '<tr>' ).append(
						$( '<td>' ).addClass( 'diff-ntitle' ).append(
							$( '<div>' ).attr( 'id', 'mw-diff-ntitle4' ).append(
								$( '<a>' ).attr( 'id', 'differences-nextlink' ),
								$header
							)
						)
					)
				),
				$belowDiff
			)
			.appendTo( '#qunit-fixture' );

		const targets = elements
			.find( ( entry ) => entry.friendlyName === 'Mark as patrolled' )
			.targets;

		assert.strictEqual(
			document.querySelector( targets.header ),
			$header.find( 'a' )[ 0 ],
			'the header selector finds the copy inside the diff table'
		);
		assert.strictEqual(
			document.querySelector( targets.below_diff ),
			$belowDiff.find( 'a' )[ 0 ],
			'the below_diff selector finds the copy after the diff table, not the header one'
		);
	} );
} );
