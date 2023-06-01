// GeoIP mapping experiments (T332024)

const Probenet = require( './probenet.js' );
const RECIPE = require( './recipe.js' );

function onComplete( report ) {
	report.$schema = '/development/network/probe/1.0.0';
	mw.eventLog.submit( 'development.network.probe', report );
	mw.cookie.set( 'PreventProbe', '1', { expires: 604800 } );
}

function doProbe() {
	const probenet = new Probenet();
	probenet.setRecipeJson( RECIPE );
	probenet.runProbe( onComplete );
}

doProbe();
