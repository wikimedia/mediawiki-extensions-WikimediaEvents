const searchSatisfaction = require( './searchSatisfaction.js' );
const searchSli = require( './searchSli.js' );

module.exports = () => {
	// For some skins searchSli may be an empty object
	// See WikimediaEventsHooks.php
	if ( typeof searchSli === 'function' ) {
		searchSli();
	}
	$( searchSatisfaction );
};
