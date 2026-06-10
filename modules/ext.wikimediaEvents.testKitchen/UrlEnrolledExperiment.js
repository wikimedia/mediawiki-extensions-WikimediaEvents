const VALID_EXPERIMENT_GROUPS = [ 'control', 'treatment' ];

class UrlEnrolledExperiment {

	constructor(
		experimentName,
		experimentGroup,
		isOverridden = false
	) {
		this.experimentName = experimentName;
		this.experimentGroup = experimentGroup;
		this.isOverridden = isOverridden;
		this.everyoneExperimentEventIntakeServiceUrl = `https://${ mw.config.get( 'wgServerName' ) }/evt-103e/v2/events?hasty=true`;
	}

	static getExperimentFromQuery( experimentMachineReadableName ) {
		let experimentGroup = 'unknown';
		let isOverridden = false;
		let experimentParams = mw.util.getArrayParam( 'experiments' );
		if ( experimentParams === null && mw.util.getParamValue( 'experiments' ) ) {
			experimentParams = [ mw.util.getParamValue( 'experiments' ) ];
		}
		if ( experimentParams !== null ) {
			const experimentParam = experimentParams.find(
				( paramValue ) => paramValue.startsWith( experimentMachineReadableName + ':' )
			);
			if ( experimentParam ) {
				[ , experimentGroup, isOverridden ] = experimentParam.split( ':' );
			}
		}

		return new UrlEnrolledExperiment(
			experimentMachineReadableName,
			experimentGroup,
			!!isOverridden
		);
	}

	send( action, interactionData ) {
		if ( !VALID_EXPERIMENT_GROUPS.includes( this.experimentGroup ) ) {
			mw.log( `Not sending data for experiment "${ this.experimentName }" due to group being "${ this.experimentGroup }"` );
			return;
		}

		const event = interactionData || {};
		event.action = action;
		event.experiment = {
			enrolled: this.experimentName,
			assigned: this.experimentGroup,
			subject_id: 'awaiting',
			coordinator: 'default',
			sampling_unit: 'edge-unique'
		};
		event.agent = {
			// TODO: if this is class is to be used beyond the WE 1.8.3 related experiments, this should be replaced by
			//       something based on the actual user agent.
			client_platform_family: 'mobile_browser',
			client_platform: 'mediawiki_js'
		};
		event.mediawiki = {
			database: mw.config.get( 'wgDBname' ),
			skin: mw.config.get( 'skin' )
		};
		event.performer = {
			is_temp: mw.user.isTemp(),
			is_logged_in: !mw.user.isAnon(),
			is_bot: mw.config.get( 'wgUserGroups' ).includes( 'bot' )
		};
		event.$schema = '/analytics/product_metrics/web/base/2.0.0';
		event.meta = {
			stream: 'product_metrics.web_base',
			domain: location.hostname
		};
		if ( this.isOverridden ) {
			const message =
				`${ this.experimentName }: The enrollment for this experiment has been overridden. ` +
				'The following event will not be sent:\n';

			// eslint-disable-next-line no-console
			console.log(
				message,
				action,
				JSON.stringify( event, null, 2 )
			);
		} else {
			const headers = { type: 'text/plain' };
			const payload = new Blob( [ JSON.stringify( event ) ], headers );
			navigator.sendBeacon(
				this.everyoneExperimentEventIntakeServiceUrl,
				payload
			);
		}
	}

	sendExposure() {
		this.send( 'experiment_exposure' );
	}
}

module.exports = UrlEnrolledExperiment;
