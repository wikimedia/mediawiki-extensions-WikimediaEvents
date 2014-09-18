( function ( mw, $ ) {
	$( window ).on( 'load', function() {
		var backend = mw.config.get( 'wgPoweredByHHVM' ) ? 'HHVM' : 'PHP5',
			respTime = mw.config.get( 'wgBackendResponseTime' ),
			caption = respTime.toString() + 'ms (<strong>' + backend + '</strong>)';

		$( '<li class="mw-backendperformance">' ).html( caption ).prependTo( '#p-personal ul' );
	} );
}( mediaWiki, jQuery ) );
