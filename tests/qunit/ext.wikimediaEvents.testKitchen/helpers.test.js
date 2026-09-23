'use strict';

const helpers = require( 'ext.wikimediaEvents.testKitchen/helpers.js' );

QUnit.module( 'ext.wikimediaEvents.testKitchen/helpers/withContext', QUnit.newMwEnvironment( {
	beforeEach() {
		this.withContext = helpers.withContext;
		this.eventSender = {
			sendCalls: [],

			send( action, interactionData, contextualAttributes ) {
				this.sendCalls.push( [ action, interactionData, contextualAttributes ] );
			},

			reset() {
				this.sendCalls = [];
			}
		};
	},

	afterEach() {
		this.eventSender.reset();
	}
} ) );

QUnit.test.each(
	'it should add the given context',
	{
		'no interaction data given': [ undefined, '{"Hello":"World!"}' ],
		'no action_context given': [ { property: 'value' }, '{"Hello":"World!"}' ],
		'action_context given': [
			{
				action_context: '{"foo":"bar"}'
			},
			'{"foo":"bar","Hello":"World!"}'
		]
	},
	function ( assert, [ interactionData, expectedActionContext ] ) {
		const instrumentation = ( eventSender ) => {
			eventSender.send( 'foo', interactionData );
		};

		const wrappedInstrumentation = this.withContext( instrumentation, {
			Hello: 'World!'
		} );

		wrappedInstrumentation( this.eventSender );

		const { sendCalls } = this.eventSender;

		assert.strictEqual( sendCalls.length, 1 );
		assert.strictEqual( sendCalls[ 0 ][ 0 ], 'foo' );
		assert.strictEqual( sendCalls[ 0 ][ 1 ].action_context, expectedActionContext );
	}
);
