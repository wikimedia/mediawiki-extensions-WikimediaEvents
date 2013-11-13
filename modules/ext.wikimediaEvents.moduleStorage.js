/**
 * Log timing data for the ResourceLoader module storage performance evaluation.
 * @see https://meta.wikimedia.org/wiki/Schema:ModuleStorage
 */
( function ( mw, $ ) {

	if (
		// Return early
		// ..if we're in debug mode.
		mw.config.get( 'debug' ) ||
		// ..if module storage is enabled by default.
		mw.config.get( 'wgResourceLoaderStorageEnabled' ) ||
		// ..if the experiment is not defined
		mw.loader.store.experiment === undefined ||
		// ..if the user is not included in the experiment.
		( mw.loader.store.experiment.group !== 1 && mw.loader.store.experiment.group !== 2 )
	) {
		return;
	}

	$( window ).load( function () {
		var store, moduleLoadingTime, event;

		store = mw.loader.store;
		moduleLoadingTime = ( new Date() ).getTime() - store.experiment.start;

		event = {
			experimentGroup: store.experiment.group,
			experimentId: store.experiment.id.toString( 16 ),
			moduleLoadingTime: moduleLoadingTime,
			moduleStoreEnabled: store.enabled,
			userAgent: navigator.userAgent,
			loadedModulesCount: 0,
			loadedModulesSize: 0
		};

		if ( mw.mobileFrontend && mw.config.exists( 'wgMFMode' ) ) {
			event.mobileMode = mw.config.get( 'wgMFMode' );
		}

		$.each( mw.inspect.getLoadedModules(), function ( i, module ) {
			event.loadedModulesCount++;
			event.loadedModulesSize += mw.inspect.getModuleSize( module );
		} );

		if ( store.enabled ) {
			event.moduleStoreExpired = store.stats.expired;
			event.moduleStoreHits = store.stats.hits;
			event.moduleStoreMisses = store.stats.misses;
		}

		mw.eventLog.logEvent( 'ModuleStorage', event );
	} );

}( mediaWiki, jQuery ) );
