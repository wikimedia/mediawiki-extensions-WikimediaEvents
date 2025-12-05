const editingSessionService = require( './editingSessionService.js' );

const EXPERIMENT_NAME = 'fy25-26-we-1-1-19-mobile-section-dead-end';
const SCHEMA_NAME = '/analytics/product_metrics/web/base/1.5.0';
const STREAM_NAME = 'mediawiki.product_metrics.contributors.experiments';

const experimentPromise = mw.loader.using( 'ext.xLab' )
	.then( () => {
		const experiment = mw.xLab.getExperiment( EXPERIMENT_NAME );
		experiment.setSchema( SCHEMA_NAME );
		experiment.setStream( STREAM_NAME );
		return experiment;
	} )
	.catch( ( error ) => {
		mw.log( 'Error loading ext.xLab module:', error );
		return null;
	} );

mw.hook( 've.newTarget' ).add( ( target ) => {
	if ( target.constructor.static.trackingName !== 'mobile' ) {
		return;
	}

	experimentPromise.then( ( exp ) => {
		if ( !( exp && exp.isAssignedGroup( 'control', 'treatment' ) ) ) {
			return;
		}

		// The user is definitely enrolled in an existing experiment by this point
		const config = mw.config.get( 'wgVisualEditorConfig' );
		config.enableSectionEditingFullPageButtons = exp.isAssignedGroup( 'treatment' );

		const send = ( action, data ) => {
			data.funnel_entry_token = editingSessionService.getEditingSessionId();
			data.action_context = data.action_context || {};
			data.action_context.interface = target.getDefaultMode() === 'source' ? 'wikitext-2017' : 'visualeditor';
			// This needs to be a string, but we've left it as an object until
			// now so it can be easily modified:
			data.action_context = JSON.stringify( data.action_context );
			exp.send( action, data );
		};

		const timings = {
			init: mw.now()
		};

		send( 'init', {
			action_subtype: target.section !== null ? 'section' : 'page',
			page: {
				namespace_id: mw.config.get( 'wgNamespaceNumber' )
			}
		} );

		target.once( 'surfaceReady', () => {
			timings.ready = mw.now();
			send( 'ready', {
				action_subtype: target.section !== null ? 'section' : 'page',
				action_context: { timing_ms: timings.ready - timings.init }
			} );
			target.surface.getModel().getDocument().once( 'transact', () => {
				timings.firstChange = mw.now();
				send( 'firstChange', {
					action_context: { timing_ms: timings.firstChange - timings.ready }
				} );
			} );
		} );
		target.once( 'save', ( data ) => {
			send( 'edit_saved', {
				page: {
					namespace_id: mw.config.get( 'wgNamespaceNumber' ),
					revision_id: data.newrevid
				}
			} );
		} );
		if ( target.section ) {
			let sectionLabel = target.section === 'new' ? 'new' : 'middle';
			const section = Number( target.section );
			if ( section === 0 ) {
				sectionLabel = 'lead';
			} else if ( section === target.$editableContent.find( '.mw-editsection' ).length ) {
				sectionLabel = 'last';
			}
			target.switchToFullPageButtonTop.on( 'click', () => {
				send( 'section_switch', { action_subtype: sectionLabel + '-top' } );
			} );
			target.switchToFullPageButtonBottom.on( 'click', () => {
				send( 'section_switch', { action_subtype: sectionLabel + '-bottom' } );
			} );
		}
	} );
} );
