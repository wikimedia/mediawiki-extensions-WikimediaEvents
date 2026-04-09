function attachPasswordRevealFunctionality() {
	Array.from( document.querySelectorAll( '#userlogin2 #wpPassword2, #userlogin2 #wpRetype' ) ).forEach( ( passwordInput ) => {
		const iconElement = Array.from( passwordInput.parentElement.children ).find(
			( element ) => element.classList.contains( 'growthexperiments-password-reveal-icon' )
		);
		iconElement.addEventListener( 'click', () => {
			passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
		} );
	} );
}

module.exports = attachPasswordRevealFunctionality;
