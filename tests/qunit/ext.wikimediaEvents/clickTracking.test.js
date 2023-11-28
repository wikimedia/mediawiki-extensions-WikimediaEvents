/* eslint-env qunit */
/* eslint-disable camelcase */
'use strict';

const desktopWebUIActions = require( 'ext.wikimediaEvents/clickTracking/utils.js' );

QUnit.module( 'ext.wikimediaEvents/clickTracking', QUnit.newMwEnvironment() );

QUnit.test( 'onClickTrack link', function ( assert ) {
	[
		[
			`<div>
				<div>
					<ul>
						<li>
							<a data-event-name="clicko">
								<span>label</spam>
							</a>
						</li>
					</ul>
				</div>
			</div>`,
			'a span',
			true,
			'A click to a link with data-event triggers a click event'
		],
		[
			`<div>
				<div>
					<ul>
						<li>
							<a>
								<span>label</spam>
							</a>
						</li>
					</ul>
				</div>
			</div>`,
			'a span',
			false,
			'A click to an ordinary link triggers no click event'
		],
		[
			`<div>
				<div>
					<ul class="vector-menu">
						<li id="foo">
							<a>
								<span>menu label</spam>
							</a>
						</li>
					</ul>
				</div>
			</div>`,
			'a span',
			true,
			'A click to link inside vector-menu triggers a click event if it has an ID on the LI element'
		]
	].forEach( ( [ html, selector, spyCalledOnce, msg ] ) => {
		const $test = $( html );
		const spy = this.sandbox.spy();
		$test.on( 'click', desktopWebUIActions.onClickTrack( spy ) );
		$test.find( selector ).trigger( new $.Event( 'click' ) );
		assert.strictEqual(
			spy.calledOnce,
			spyCalledOnce,
			msg
		);
	} );
} );
