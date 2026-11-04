// When wp-admin itself runs inside a frame (WordPress Playground, some
// hosting dashboards), DownTime's approval page refuses to load in it
// (X-Frame-Options: DENY, against clickjacking), so Connect opens a new tab.
( function () {
	var link = document.getElementById( 'downtime-connect' );
	if ( link && window.top !== window.self ) {
		link.target = '_blank';
		link.rel = 'noopener';
	}
} )();
