/**
 * Hidden Categories — page behaviour.
 *
 * Split of responsibility:
 *   hidden.css   does the actual hiding, from attributes this file sets
 *   this file    sets those attributes, and adds the conveniences
 *
 * Everything here is defensive on purpose. A theme that renames a class, a
 * FreshRSS upgrade that moves the tree, or a missing element must leave the
 * page working: every lookup is guarded, nothing throws on absence, and the
 * core hiding (attributes + CSS) does not depend on any of the DOM surgery
 * below. If this file fails to load entirely, hiding still works because the
 * attributes are also set from the inline call at the bottom of the page
 * (see extension.php: injectJsVars sets data-hc-* via the payload we read).
 */
(function () {
	'use strict';

	function payload() {
		var ctx = window.context || {};
		var ext = ctx.extensions || {};
		return ext.hiddenCategories || null;
	}

	var HC = payload();
	if (!HC || typeof HC !== 'object') {
		return;                      // extension off: touch nothing
	}

	var cats = Array.isArray(HC.cats) ? HC.cats.map(Number) : [];
	var meta = HC.meta && typeof HC.meta === 'object' ? HC.meta : {};
	var only = !!HC.only;
	var isHidden = {};
	for (var i = 0; i < cats.length; i++) {
		isHidden[cats[i]] = true;
	}

	var root = document.documentElement;

	// ---- 1. the attributes the stylesheet keys off -------------------------
	//
	// Set immediately, before any DOM walk, so the CSS can hide things at first
	// paint. This is the only part of this file the core behaviour depends on.
	root.setAttribute('data-hc-hidden', cats.join(','));
	root.setAttribute('data-hc-only', only ? '1' : '0');
	root.setAttribute('data-hc-mode', 'hide');

	// ---- 2. per-id rules, injected once ------------------------------------
	//
	// The hiding selectors need one line per hidden category. Generating them
	// here keeps hidden.css static and cacheable, and costs a few bytes even
	// with every category hidden. Both hooks below already exist in stock
	// FreshRSS markup, so no DOM surgery is required for the hiding itself:
	//   sidebar   <li id="c_<catId>">
	//   stream     <div class="flux" data-category="<catId>">
	function markStyles() {
		if (cats.length === 0) { return; }
		if (document.getElementById('hc-rules')) { return; }
		var rules = [];
		for (var n = 0; n < cats.length; n++) {
			var id = cats[n];
			rules.push('html[data-hc-mode="hide"] li.tree-folder.category#c_' + id + '{display:none!important}');
			rules.push('html[data-hc-mode="hide"] .flux[data-category="' + id + '"]{display:none!important}');
			rules.push('html[data-hc-only="1"] .flux[data-category="' + id + '"]{display:block!important}');
		}
		var st = document.createElement('style');
		st.id = 'hc-rules';
		st.textContent = rules.join('\n');
		(document.head || document.documentElement).appendChild(st);
	}

	// Markers for anything the CSS cannot reach by id (a theme that renames the
	// tree classes). Purely additive: failing to find them hides nothing extra.
	function markTree() {
		var nodes = document.querySelectorAll('.tree-folder.category[id^="c_"]');
		for (var n = 0; n < nodes.length; n++) {
			var el = nodes[n];
			var id = parseInt(String(el.id).replace(/^c_/, ''), 10);
			if (!id) { continue; }
			el.setAttribute('data-hc-cat', String(id));
			el.setAttribute('data-hc-hidden', isHidden[id] ? '1' : '0');
		}
	}

	function markStream() {
		var nodes = document.querySelectorAll('.flux[data-category]');
		for (var n = 0; n < nodes.length; n++) {
			var el = nodes[n];
			var id = parseInt(el.getAttribute('data-category'), 10);
			if (!id) { continue; }
			el.setAttribute('data-hc-hidden', isHidden[id] ? '1' : '0');
		}
	}

	function markAll() {
		markStyles();
		markTree();
		markStream();
	}

	// ---- 3. post a change -------------------------------------------------
	//
	// Every control funnels through here, and every one of them hits the same
	// server action. One write path means the sidebar, the menu and the settings
	// page cannot drift apart.
	function post(extra, done) {
		if (!HC.api) { return; }
		var csrfEl = document.querySelector('input[name="_csrf"]');
		var csrf = (window.context && window.context.csrf) || (csrfEl ? csrfEl.value : '');
		var body = new URLSearchParams();
		body.append('_csrf', csrf);
		Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); });

		fetch(HC.api, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		}).then(function (r) {
			if (r.ok || r.redirected) {
				if (done) { done(); }
				window.location.reload();
			} else {
				toast('Could not save (HTTP ' + r.status + ')', true);
			}
		}).catch(function () {
			toast('Could not reach the server', true);
		});
	}

	// ---- 4. toast ---------------------------------------------------------
	function toast(msg, bad) {
		var n = document.createElement('div');
		n.textContent = msg;
		n.className = 'hc-toast';
		n.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);'
			+ 'z-index:99999;padding:10px 18px;border-radius:7px;font-size:13px;'
			+ 'background:' + (bad ? '#a83232' : '#333') + ';color:#fff;opacity:0;'
			+ 'transition:opacity .18s ease;box-shadow:0 4px 14px rgba(0,0,0,.35)';
		document.body.appendChild(n);
		requestAnimationFrame(function () { n.style.opacity = '1'; });
		setTimeout(function () {
			n.style.opacity = '0';
			setTimeout(function () { if (n.parentNode) { n.parentNode.removeChild(n); } }, 220);
		}, bad ? 4000 : 2200);
	}

	// ---- 5. the sidebar "Hidden (N)" section ------------------------------
	function buildHiddenSection() {
		if (cats.length === 0) { return; }
		var tree = document.querySelector('nav#sidebar .tree') || document.querySelector('.tree');
		if (!tree) { return; }
		if (document.getElementById('hc-hidden-tree')) { return; }

		var counts = 0;
		cats.forEach(function (id) {
			if (meta[id] && meta[id].feeds) { counts += Number(meta[id].feeds) || 0; }
		});

		var li = document.createElement('li');
		li.id = 'hc-hidden-tree';
		li.className = 'tree-folder category hc-tree';

		var title = document.createElement('a');
		title.className = 'tree-folder-title';
		title.href = '#';
		title.setAttribute('role', 'button');
		title.setAttribute('aria-expanded', 'false');
		title.innerHTML = '<button class="dropdown-toggle" type="button" title="Expand">'
			+ '<span class="hc-caret">&#9662;</span></button>'
			+ '<span class="title">Hidden (' + cats.length + ')</span>';

		var chip = document.createElement('span');
		chip.className = 'hc-chip';
		chip.textContent = HC.mode === 'strict' ? 'enforced' : 'visual';
		chip.title = HC.mode === 'strict'
			? 'Feeds in these categories have FreshRSS\'s "only show in this category" setting.'
			: 'Nothing is written to your feeds; this is filtered in the browser.';
		title.appendChild(chip);

		var ul = document.createElement('ul');
		ul.className = 'tree-folder-items';
		ul.style.display = 'none';

		cats.forEach(function (id) {
			var info = meta[id] || {};
			var item = document.createElement('li');
			item.className = 'item hc-item';

			var a = document.createElement('a');
			a.className = 'hc-name';
			a.href = './?get=c_' + id;
			a.textContent = info.name || ('Category ' + id);

			var count = document.createElement('span');
			count.className = 'hc-count';
			count.textContent = info.feeds != null ? info.feeds + ' feeds' : '';

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'hc-unhide';
			btn.title = 'Show this category again';
			btn.setAttribute('aria-label', 'Unhide ' + (info.name || ('category ' + id)));
			btn.textContent = '\u00D7';
			btn.addEventListener('click', function (ev) {
				ev.preventDefault();
				ev.stopPropagation();
				post({ act: 'unhide', cat: id });
			});

			item.appendChild(a);
			item.appendChild(count);
			item.appendChild(btn);
			ul.appendChild(item);
		});

		// "only hidden" toggle, on the section row so it is one click from the
		// place you already go to look at hidden things
		var onlyRow = document.createElement('li');
		onlyRow.className = 'item hc-item hc-only-row';
		var onlyBtn = document.createElement('button');
		onlyBtn.type = 'button';
		onlyBtn.className = 'hc-unhide';
		onlyBtn.style.width = '100%';
		onlyBtn.style.textAlign = 'left';
		onlyBtn.style.opacity = '0.8';
		onlyBtn.textContent = only ? 'Show everything' : 'View only these';
		onlyBtn.title = only
			? 'Leave the hidden-only view'
			: 'Dim everything else and show only hidden categories';
		onlyBtn.addEventListener('click', function (ev) {
			ev.preventDefault();
			post({ act: 'only', on: only ? '0' : '1' });
		});
		onlyRow.appendChild(onlyBtn);
		ul.appendChild(onlyRow);

		title.addEventListener('click', function (ev) {
			ev.preventDefault();
			var open = ul.style.display !== 'none';
			ul.style.display = open ? 'none' : '';
			title.setAttribute('aria-expanded', open ? 'false' : 'true');
		});

		li.appendChild(title);
		li.appendChild(ul);
		tree.appendChild(li);
	}

	// ---- 6. the "only hidden" banner --------------------------------------
	function buildBanner() {
		if (!only) { return; }
		var stream = document.getElementById('stream');
		if (!stream || !stream.parentNode) { return; }
		if (document.getElementById('hc-banner')) { return; }

		var bar = document.createElement('div');
		bar.id = 'hc-banner';
		bar.className = 'hc-banner';
		bar.innerHTML = '<span>Showing only your hidden categories \u2014 '
			+ cats.length + ' hidden.</span>';

		var off = document.createElement('button');
		off.type = 'button';
		off.textContent = 'Show everything';
		off.addEventListener('click', function () {
			post({ act: 'only', on: '0' });
		});
		bar.appendChild(off);
		stream.parentNode.insertBefore(bar, stream);
	}

	// ---- 7. keep markers correct as the page changes ----------------------
	//
	// Infinite scroll and "load more" append new .flux nodes. One observer that
	// only marks newly added items is cheaper than a polling loop and cannot
	// miss the ones the user actually scrolls to.
	function watch() {
		if (typeof MutationObserver !== 'function') { return; }
		var target = document.getElementById('stream');
		if (!target) { return; }
		var pending = false;
		var obs = new MutationObserver(function () {
			if (pending) { return; }
			pending = true;
			requestAnimationFrame(function () {
				pending = false;
				markStream();
			});
		});
		obs.observe(target, { childList: true, subtree: true });
	}

	function boot() {
		markAll();
		buildHiddenSection();
		buildBanner();
		watch();
	}

	if (document.readyState === 'loading') {
		// attributes are already set above, before first paint; the DOM work
		// waits for the tree to exist
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
