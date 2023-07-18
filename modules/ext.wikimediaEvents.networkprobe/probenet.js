/* eslint-disable camelcase */

// Library Name   :   Probenet
// Repo           :   https://gitlab.wikimedia.org/repos/sre/probenet

class Probenet {
	// Constructor of Probenet
	constructor() {
		// Function to make requests to targets
		// Using FetchAPI by default to make requests to targets
		this.loader = this.loadUsingFetch;

		// Function to handle logs
		// Disabling logging by default
		this.logger = () => {};

		// Object containing information about the probe to be done
		this.recipe = null;

		// The URL to load the recipe from
		this.recipeUrl = null;

		// Map containing target_name keys and target_url values
		this.targets = null;

		// Map containing target_name keys and pulse result values
		this.targetData = null;
	}

	// Method to set the URL of the recipe
	setRecipeUrl( recipeUrl ) {
		this.recipe = null;
		this.recipeUrl = recipeUrl;
		this.targets = null;
		this.targetData = null;
	}

	// Method to set the json recipe
	setRecipeJson( recipeJson ) {
		this.recipe = recipeJson;
		this.recipeUrl = null;
		this.targets = null;
		this.targetData = null;
	}

	// Method to use FetchAPI for making requests
	useFetch() {
		this.loader = this.loadUsingFetch;
	}

	// Method to use XMLHttpRequest for making requests
	useXHR() {
		this.loader = this.loadUsingXHR;
	}

	// Method to set logger function
	setLogger( logger ) {
		this.logger = logger;
	}

	// Method to run the actual probe
	// The report will be generated and passed to onComplete callback function
	runProbe( onComplete ) {
		return new Promise( ( resolve, reject ) => {
			// Checking if user has provided any recipe
			if ( !this.recipe && !this.recipeUrl ) {
				reject( 'No recipe found!' );
				return;
			}

			// Fetching the recipe if user has provided URL of the recipe
			// and starting the probe
			this.fetchRecipe().then(
				() => {
					// Handling probes based on recipe_type
					if ( this.recipe.type === 'http_get' ) {
						return this.probeHttpGet();
					} else {
						reject( 'Unsupported recipe!' );
						return;
					}
				}
			).then(
				( report ) => {
					this.targetData = null;
					onComplete( report );
				}
			);
		} );
	}

	// Method to fetch the recipe from the URL
	fetchRecipe() {
		return new Promise( ( resolve ) => {
			if ( this.recipe ) {
				if ( !this.targets || !this.targetData ) {
					this.setupRecipe();
				}
				resolve();
				return;
			}

			fetch( this.recipeUrl ).then(
				( response ) => response.json()
			).then(
				( data ) => {
					this.recipe = data;
					if ( !this.targets || !this.targetData ) {
						this.setupRecipe();
					}
					resolve();
				}
			);
		} );
	}

	// Method to set up the recipe, populate targets and targetData
	setupRecipe() {
		this.targets = new Map();
		for ( const target of this.recipe.targets ) {
			this.targets.set(
				target.name,
				target.target
			);
		}

		this.targetData = new Map();
		for ( const target of this.recipe.targets ) {
			this.targetData.set(
				target.name,
				[]
			);
		}
	}

	// 'http_get' makes an HTTP GET request to the target
	probeHttpGet() {
		return new Promise( ( resolve ) => {
			const pulses = this.recipe.pulses;

			// Repeat the probe multiple times
			this.probeHttpGetPulse( pulses ).then( () => {
				// Generate the report
				const report = this.generateReport();
				resolve( report );
			} );
		} );
	}

	probeHttpGetPulse( pulses ) {
		return new Promise( ( resolve, reject ) => {
			if ( pulses <= 0 ) {
				resolve();
				return;
			}

			const pulse_number = this.recipe.pulses - pulses;
			const pulse_delay = this.recipe.pulse_delay;
			const serial_probe_condition = this.recipe.serial_probe === undefined;
			const serial_probe = serial_probe_condition ? false : this.recipe.serial_probe;
			const targets = Array.from( this.targets.keys() );

			// Shuffle targets to randomize bias towards first target
			this.shuffleArray( targets );

			const probes = [];

			for ( const target of targets ) {
				probes.push( this.probeHttpGetTarget( target, pulse_number ) );

				// Wait for current request to complete for serial probes
				if ( serial_probe ) {
					reject( 'serial_probe is not supported!' );
					return;
				}
			}

			// Wait for all probes to finish
			Promise.all( probes ).then( () => {
				// Wait for pulse_delay
				this.delay( pulse_delay ).then(
					() => this.probeHttpGetPulse( pulses - 1 )
				).then(
					() => resolve()
				);
			} );
		} );
	}

	// Make HTTP Get request to target
	probeHttpGetTarget( target, pulse_number ) {
		return new Promise( ( resolve, reject ) => {
			let target_url = new URL( this.targets.get( target ) );
			let pulse_identifier;

			const url_metadata_condition = this.recipe.url_metadata === undefined;
			const url_metadata = url_metadata_condition ? false : this.recipe.url_metadata;
			if ( url_metadata ) {
				pulse_identifier = this.generateIdentifier( target, pulse_number );
				target_url = this.add_url_metadata( target_url, pulse_identifier, pulse_number );
			}

			const pulse_timeout = this.recipe.pulse_timeout;
			const request = this.loader( target_url );
			const timeout = this.delay_rej( pulse_timeout );
			try {
				Promise.race( [ request, timeout ] ).then( () => {
					this.handleProbeResult( target, pulse_identifier, pulse_number );
					resolve();
				} ).catch( () => {
					reject();
				} );
			} catch ( error ) {
				reject();
			}
		} );
	}

	// Load request using FetchAPI
	loadUsingFetch( target_url ) {
		return new Promise( ( resolve ) => {
			fetch( target_url ).then(
				( response ) => response.blob()
			).then( () => {
				resolve();
			} );
		} );
	}

	// Load request using XHR
	loadUsingXHR( target_url ) {
		return new Promise( ( resolve ) => {
			const xhr = new XMLHttpRequest();
			xhr.open( 'GET', target_url, true );
			xhr.onreadystatechange = () => {
				switch ( xhr.readyState ) {
					case 4:
						resolve();
						break;
				}
			};
			xhr.send();
		} );
	}

	// Add probe data to targetData
	handleProbeResult( target, pulse_identifier, pulse_number ) {
		let target_url = this.targets.get( target );

		const url_metadata_condition = this.recipe.url_metadata === undefined;
		const url_metadata = url_metadata_condition ? false : this.recipe.url_metadata;
		if ( url_metadata ) {
			target_url = this.add_url_metadata( target_url, pulse_identifier, pulse_number );
		}

		const probe_results = performance.getEntriesByName( target_url );
		const probe_result = probe_results[ probe_results.length - 1 ];
		const probe_data = this.extractProbeData( probe_result, pulse_identifier, pulse_number );
		this.targetData.get( target ).push( probe_data );
	}

	// Extract data from probe_result
	extractProbeData( probe_result, pulse_identifier, pulse_number ) {
		const probe_data = [
			[ 'redirect_time_ms', probe_result.redirectStart - probe_result.redirectEnd ],
			[ 'dns_time_ms', probe_result.domainLookupEnd - probe_result.domainLookupStart ],
			[ 'tcp_time_ms', probe_result.secureConnectionStart - probe_result.connectStart ],
			[ 'tls_time_ms', probe_result.connectEnd - probe_result.secureConnectionStart ],
			[ 'request_time_ms', probe_result.responseStart - probe_result.requestStart ],
			[ 'response_time_ms', probe_result.responseEnd - probe_result.responseStart ],
			[ 'ttfb_ms', probe_result.responseStart - probe_result.startTime ],
			[ 'duration_ms', probe_result.duration ],
			[ 'status_code', probe_result.responseStatus ],
			[ 'transfer_bytes', probe_result.encodedBodySize ],
			[ 'actual_bytes', probe_result.decodedBodySize ]
		].reduce( function ( prev, cur ) {
			const value = Math.round( cur[ 1 ] );

			// Some of the properties can be undefined in some browsers
			// Math.round( undefined ) returns NaN
			// NaN becomes null after JSON serialisation.
			// This causes validation errors that cause some alarms to trigger
			// See: https://phabricator.wikimedia.org/T334417#8958498
			if ( !isNaN( value ) ) {
				prev[ cur[ 0 ] ] = value;
			}

			return prev;
		}, {} );

		const url_metadata_condition = this.recipe.url_metadata === undefined;
		const url_metadata = url_metadata_condition ? false : this.recipe.url_metadata;
		if ( url_metadata ) {
			probe_data.pulse_identifier = pulse_identifier;
			probe_data.pulse_number = pulse_number;
		}

		return probe_data;
	}

	// Function to generate report after all probes are completed
	generateReport() {
		const report = {};
		report.recipe_name = this.recipe.name;
		report.recipe_type = this.recipe.type;

		// Pass context from recipe to report
		report.ctx = this.recipe.ctx;

		report.reports = [];
		for ( const [ name, target ] of this.targets ) {
			const target_data = {};
			target_data.target_name = name;
			target_data.target_url = target;
			target_data.pulses = this.targetData.get( name );
			report.reports.push( target_data );
		}

		return report;
	}

	// Function to add metadata to url
	add_url_metadata( target_url, pulse_identifier, pulse_number ) {
		const url = new URL( target_url );
		url.searchParams.set( 'pulse_identifier', pulse_identifier );
		url.searchParams.set( 'pulse_number', pulse_number );
		return url.toString();
	}

	// Helper function to generate a random identifier
	// WikimediaEvents specific implementation of generateIdentifier
	generateIdentifier( target, pulse_number ) {
		const token = mw.user.getPageviewToken();
		const identifier = `${token}_${target}_${pulse_number}`;
		return identifier;
	}

	// Helper function to shuffle array
	shuffleArray( array ) {
		for ( let i = array.length - 1; i > 0; i-- ) {
			const j = Math.floor( Math.random() * ( i + 1 ) );
			const temp = array[ i ];
			array[ i ] = array[ j ];
			array[ j ] = temp;
		}
	}

	// Helper function to delay execution
	delay( ms ) {
		return new Promise( ( resolve ) => {
			setTimeout( resolve, ms );
		} );
	}

	// Helper function to delay execution and raise an exception
	delay_rej( ms ) {
		return new Promise( ( resolve, reject ) => {
			setTimeout( reject, ms );
		} );
	}
}

// Exporting Probenet module
module.exports = Probenet;
