const setUpSuggestionDiscoverabilityExperiment = require( './suggestionDiscoverability.js' );
const getSuggestionExperimentExclusion = require( './suggestionEligibility.js' );

setUpSuggestionDiscoverabilityExperiment(
	'suggestion-mode-onboarding', [ 'suggestions-onboarding' ], getSuggestionExperimentExclusion
);
