'use strict';

const donorDelightBadgeExperiment = require( 'ext.wikimediaEvents/donorDelightBadgeExperiment.js' );

const RECENT_DONOR_HOOK = 'wikimediaCustomizations.donor.recentDonor';

/**
 * Isolated hook registry for tests (avoids mw.hook memory leaking between tests).
 *
 * @return {Object}
 */
function createRecentDonorHook() {
	const handlers = [];
	let memory = null;

	return {
		add( ...fns ) {
			handlers.push( ...fns );
			if ( memory !== null ) {
				for ( const fn of fns ) {
					fn( ...memory );
				}
			}
			return this;
		},
		remove( ...fns ) {
			for ( const fn of fns ) {
				let j;
				while ( ( j = handlers.indexOf( fn ) ) !== -1 ) {
					handlers.splice( j, 1 );
				}
			}
			return this;
		},
		fire( ...data ) {
			memory = data;
			for ( const fn of handlers ) {
				fn.apply( null, data );
			}
			return this;
		}
	};
}

QUnit.module( 'ext.wikimediaEvents/donorDelightBadgeExperiment', QUnit.newMwEnvironment( {
	beforeEach() {
		const realHook = mw.hook;

		this.recentDonorHook = createRecentDonorHook();
		this.sandbox.stub( mw, 'hook' ).callsFake( ( name ) => {
			if ( name === RECENT_DONOR_HOOK ) {
				return this.recentDonorHook;
			}
			return realHook( name );
		} );
		this.sandbox.stub( mw.loader, 'using' ).resolves();
	}
} ) );

QUnit.test( 'setupDonorDelightBadgeExperimentInstrumentation sends page_visit and exposure for control when recent donor hook fires', function ( assert ) {
	const experiment = this.sandbox.spy( {
		isAssignedGroup( ...groups ) {
			return groups.includes( 'control' );
		},
		send() {},
		sendExposure() {}
	} );

	donorDelightBadgeExperiment.test.setupDonorDelightBadgeExperimentInstrumentation( experiment );

	assert.true( experiment.send.calledOnceWith( 'page_visit' ) );
	assert.true( experiment.sendExposure.notCalled );

	mw.hook( RECENT_DONOR_HOOK ).fire();

	assert.true( experiment.sendExposure.calledOnce );
} );

QUnit.test( 'setupDonorDelightBadgeExperimentInstrumentation defers control exposure until recent donor hook fires', function ( assert ) {
	const experiment = this.sandbox.spy( {
		isAssignedGroup( ...groups ) {
			return groups.includes( 'control' );
		},
		send() {},
		sendExposure() {}
	} );

	donorDelightBadgeExperiment.test.setupDonorDelightBadgeExperimentInstrumentation( experiment );

	assert.true( experiment.send.calledOnceWith( 'page_visit' ) );
	assert.true( experiment.sendExposure.notCalled );
} );

QUnit.test( 'setupDonorDelightBadgeExperimentInstrumentation sends exposure for treatment when recent donor hook fires', function ( assert ) {
	const experiment = this.sandbox.spy( {
		isAssignedGroup( ...groups ) {
			return groups.includes( 'treatment-b-simple' );
		},
		send() {},
		sendExposure() {}
	} );

	donorDelightBadgeExperiment.test.setupDonorDelightBadgeExperimentInstrumentation( experiment );

	assert.true( experiment.sendExposure.notCalled );

	mw.hook( RECENT_DONOR_HOOK ).fire();

	assert.true( experiment.send.calledOnceWith( 'page_visit' ) );
	assert.true( experiment.sendExposure.calledOnce );
} );

QUnit.test( 'setupDonorDelightBadgeExperimentInstrumentation sends exposure when recent donor hook fired before setup', function ( assert ) {
	const experiment = this.sandbox.spy( {
		isAssignedGroup( ...groups ) {
			return groups.includes( 'treatment-c-delightful' );
		},
		send() {},
		sendExposure() {}
	} );

	mw.hook( RECENT_DONOR_HOOK ).fire();

	donorDelightBadgeExperiment.test.setupDonorDelightBadgeExperimentInstrumentation( experiment );

	assert.true( experiment.send.calledOnceWith( 'page_visit' ) );
	assert.true( experiment.sendExposure.calledOnce );
} );

QUnit.test( 'setupDonorDelightBadgeExperimentInstrumentation noops when not enrolled', function ( assert ) {
	const experiment = this.sandbox.spy( {
		isAssignedGroup() {
			return false;
		},
		send() {},
		sendExposure() {}
	} );

	donorDelightBadgeExperiment.test.setupDonorDelightBadgeExperimentInstrumentation( experiment );

	assert.true( experiment.send.notCalled );
	assert.true( experiment.sendExposure.notCalled );
} );
