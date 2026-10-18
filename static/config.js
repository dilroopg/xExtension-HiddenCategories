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

		bindLabelPreview(root);

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

	/**
	 * Live preview + dirty marker for the sidebar row form.
	 *
	 * The saved state is drawn by the server in the "Now reads" row. This
	 * handles the draft: ticking or typing re-renders "After saving" instantly
	 * and says whether the draft differs from what is stored, so both controls
	 * give the same feedback and neither saves without the button. With this
	 * script absent the form still saves — you just don't get the preview.
	 */
	function bindLabelPreview(root) {
		var form = document.getElementById('hidden-categories-display-form');
		if (!form) { return; }
		var input = form.querySelector('[data-hc-label]');
		var box = form.querySelector('[data-hc-count]');
		var live = root.querySelector('[data-hc-live]');
		var msg = root.querySelector('[data-hc-live-msg]');
		var dirty = root.querySelector('[data-hc-dirty]');
		if (!input || !box || !live || !msg) { return; }

		// the real hidden count, taken from the saved row so this cannot drift
		var num = root.querySelector('.hc-preview .hc-row-num');
		var count = num ? num.textContent : '';

		// the saved state, captured once from the server-rendered form
		var savedLabel = input.value.replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
		var savedCount = box.defaultChecked;

		function esc(s) {
			return String(s).replace(/[&<>"']/g, function (c) {
				return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
			});
		}

		function render() {
			var label = input.value.replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
			var caret = '<span class="hc-caret" aria-hidden="true">&#9662;</span>';
			var changed = (label !== savedLabel) || (box.checked !== savedCount);

			if (!box.checked) {
				live.innerHTML = caret;
			} else if (label === '') {
				live.innerHTML = caret + '<span class="hc-row-num">' + esc(count) + '</span>';
			} else {
				live.innerHTML = caret
					+ '<span class="hc-row-label">' + esc(label) + '</span>'
					+ '<span class="hc-row-num">' + esc(count) + '</span>';
			}

			if (!box.checked) {
				msg.textContent = 'the row will show the caret only';
			} else if (label === '' && !changed) {
				msg.textContent = 'unchanged — showing the number alone';
			} else if (!changed) {
				msg.textContent = 'unchanged';
			} else {
				msg.textContent = 'preview of your unsaved changes';
			}

			if (dirty) {
				dirty.textContent = changed ? 'Unsaved changes — press Save' : '';
			}
		}

		input.addEventListener('input', render);
		box.addEventListener('change', render);
		render();
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
