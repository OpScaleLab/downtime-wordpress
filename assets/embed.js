/*
 * Auto-size [downtime_status] iframes. In ?embed=1 mode the DownTime status
 * page posts {type: 'downtime:status:resize', slug, height} to its parent
 * whenever its content height changes. Only messages from the DownTime
 * origin, and only for the iframe that actually sent them, are honored.
 */
(function () {
	var origin = (window.downtimeEmbed && window.downtimeEmbed.origin) || 'https://downtime.opscale.ir';
	window.addEventListener('message', function (e) {
		if (e.origin !== origin || !e.data || e.data.type !== 'downtime:status:resize') return;
		var height = parseInt(e.data.height, 10);
		if (!(height > 0)) return;
		var frames = document.querySelectorAll('iframe.downtime-status');
		for (var i = 0; i < frames.length; i++) {
			if (frames[i].contentWindow === e.source) {
				frames[i].style.height = Math.min(height, 5000) + 'px';
			}
		}
	});
})();
