/**
 * Module storage experiment clean-up: purge 'moduleStorageExperiment' key from
 * localStorage, set by MediaWiki in Id2835eca4. This module should be deleted
 * after spending a couple of weeks in production.
 */
try {
	localStorage.removeItem( 'moduleStorageExperiment' );
} catch ( e ) {}
