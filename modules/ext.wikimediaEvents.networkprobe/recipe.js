const RECIPE = {
	name: 'Latency Test',
	type: 'http_get',
	pulses: 3,
	pulse_delay: 500,
	pulse_timeout: 15000,
	url_metadata: true,
	serial_probe: false,
	targets: [
		{
			name: 'esams',
			target: 'https://measure-esams.wikimedia.org/measure'
		},
		{
			name: 'eqiad',
			target: 'https://measure-eqiad.wikimedia.org/measure'
		},
		{
			name: 'drmrs',
			target: 'https://measure-drmrs.wikimedia.org/measure'
		},
		{
			name: 'codfw',
			target: 'https://measure-codfw.wikimedia.org/measure'
		},
		{
			name: 'eqsin',
			target: 'https://measure-eqsin.wikimedia.org/measure'
		},
		{
			name: 'ulsfo',
			target: 'https://measure-ulsfo.wikimedia.org/measure'
		}
	],
	ctx: {
		server: 'WikimediaEvents-static-1'
	}
};

module.exports = RECIPE;
