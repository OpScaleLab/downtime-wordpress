// When wp-admin itself runs inside a frame (WordPress Playground, some
// hosting dashboards), DownTime's approval page refuses to load in it
// (X-Frame-Options: DENY, against clickjacking), so Connect opens a new tab.
( function () {
	if ( window.top === window.self ) {
		return;
	}
	var input = document.querySelector( 'form input[name="action"][value="downtime_connect"]' );
	if ( input ) {
		input.form.target = '_blank';
	}
} )();
