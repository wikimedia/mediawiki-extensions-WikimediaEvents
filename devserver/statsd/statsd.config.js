// See https://github.com/statsd/statsd/blob/8e6e29ea1f00062c9be27eebb122fa8c427bc74b/exampleConfig.js for a detailed
// example of a statsd configuration file.

{
	backends: [ './backends/console' ],

	// It is useful to see messages as they are received as well as in the stats that are flushed to the console
	// backend service (see above).
	dumpMessages: true
};
