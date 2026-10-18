/**
 * Hidden Categories — settings page behaviour.
 *
 * This file is registered with appendScript from init(), never inlined in
 * configure.phtml: the configure view is injected into the AJAX slider and
 * inline <script> in an injected fragment does not execute.
 *
 * Step 1 rule: the page works with this script absent. Nothing the server
 * needs (act, mode, _csrf) is filled in by JavaScript — the only thing this
 * does is confirm the mode change out loud before submitting, and keep the
 * page from submitting a mode the user did not pick.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function bind() {
		var root = document.getElementById('hidden-categories-config');
		if (!root) { return; }
		if (root.getAttribute('data-hc-bound') === '1') { return; }
		root.setAttribute('data-hc-bound', '1');

		var form = document.getElementById('hidden-categories-mode-form');
		if (!form) { return; }

		form.addEventListener('submit', function (ev) {
			var picked = form.querySelector('input[name="mode"]:checked');
			if (!picked) {
				// No radio chosen: refuse locally instead of sending an empty
				// field the server would have to reject anyway.
				ev.preventDefault();
				window.alert('Pick a mode first — nothing has been changed.');
				return;
			}
			if (picked.value === 'strict') {
				var ok = window.confirm(
					'Strict mode writes FreshRSS\'s "only show in this category" setting to the feeds '
					+ 'of every hidden category, after saving their current value.\n\n'
					+ 'Nothing is hidden yet in this build, so nothing will be written right now. '
					+ 'Save the mode?'
				);
				if (!ok) { ev.preventDefault(); }
			}
		});
	}

	ready(bind);
	// The configure view can be injected after DOMContentLoaded; poll briefly.
	var tries = 0;
	var timer = window.setInterval(function () {
		tries++;
		if (document.getElementById('hidden-categories-config') || tries > 40) {
			window.clearInterval(timer);
			bind();
		}
	}, 250);
}());
