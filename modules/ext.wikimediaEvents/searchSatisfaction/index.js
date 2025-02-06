const searchSatisfaction = require( './searchSatisfaction.js' );
const searchSli = require( './searchSli.js' );

module.exports = () => {
	searchSli();
	$( searchSatisfaction );
};
