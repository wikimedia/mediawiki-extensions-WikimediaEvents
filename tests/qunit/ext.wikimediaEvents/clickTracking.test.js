/* eslint-env qunit */

'use strict';

const desktopWebUIActions = require( 'ext.wikimediaEvents/clickTracking/utils.js' );

QUnit.module( 'ext.wikimediaEvents/clickTracking', QUnit.newMwEnvironment() );

QUnit.test( 'onClickTrack input', function ( assert ) {
	const $test = $(
		`<div class="mw-portlet mw-portlet-vector-client-prefs-vector-feature-limited-width vector-menu" id="vector-client-prefs-vector-feature-limited-width">
			<div class="vector-menu-heading">Width</div>
			<div class="vector-menu-content">
				<ul class="vector-menu-content-list">
					<li class="mw-list-item mw-list-item-js">
						<div><form>
							<div class="cdx-radio" data-event-name="not-valid">
								<input name="vector-client-pref-vector-feature-limited-width-group"
									id="vector-client-pref-vector-feature-limited-width-value-1"
									data-event-name="allowed"
									type="radio" value="1" class="cdx-radio__input">
								<span class="cdx-radio__icon"></span>
								<label class="cdx-radio__label"
									data-event-name="not-valid"
									for="vector-client-pref-vector-feature-limited-width-value-1">Standard</label>
							</div>
						</div>
					</li>
				</ul>
			</div>
		</div>`
	);
	const spy = this.sandbox.spy();
	$test.on( 'click', desktopWebUIActions.onClickTrack( spy ) );
	// A click to a label may also trigger a click event on input (T352075)
	$test.find( 'label' ).trigger( new $.Event( 'click' ) );
	$test.find( 'input' ).trigger( new $.Event( 'click' ) );
	assert.strictEqual(
		spy.calledOnce,
		true,
		'A click to label only triggers one event'
	);
} );

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
