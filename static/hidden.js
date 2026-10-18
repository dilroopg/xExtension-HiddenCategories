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
	var view = HC.view === 'revealed' || HC.view === 'only' ? HC.view : 'hidden';
	var next = HC.next || 'hidden';
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
	root.setAttribute('data-hc-view', view);
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
			// sidebar: gone only in the 'hidden' view; revealed/only must show it
			rules.push('html[data-hc-mode="hide"][data-hc-view="hidden"] li.tree-folder.category#c_'
				+ id + '{display:none!important}');
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
			var hid = !!isHidden[id];
			el.setAttribute('data-hc-hidden', hid ? '1' : '0');

			// Stream filtering differs per view, so it is decided here rather
			// than in a static rule:
			//   hidden / revealed -> hide articles from hidden categories
			//   only              -> hide everything that is NOT hidden
			// 'revealed' deliberately does NOT flood the stream: revealing is a
			// sidebar act (finding what you hid), not a stream act.
			var out;
			if (view === 'only') {
				out = !hid;
			} else {
				out = hid;
			}
			el.classList.toggle('hc-stream-out', out);
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

	// ---- 5. the one button: hidden -> revealed -> only -> hidden ----------
	//
	// Deliberately ONE control, in one place, cycling three states. Anything
	// that hides things (settings page, subscription page, right-click) calls
	// the same server action, so this button always reflects reality.
	var LABELS = {
		hidden: { text: 'Show hidden', title: 'Reveal the hidden categories in the sidebar' },
		revealed: { text: 'Show only hidden', title: 'Dim everything else and show only hidden categories' },
		only: { text: 'Hide them again', title: 'Go back to hiding them' }
	};

	function buildButton() {
		var bar = document.getElementById('hc-sidebar-bar');
		if (!bar) {
			var tree = document.querySelector('nav#sidebar .tree') || document.querySelector('.tree');
			if (!tree) { return; }
			bar = document.createElement('div');
			bar.id = 'hc-sidebar-bar';
			bar.className = 'hc-bar';
			// sits directly above the tree, so it is the first thing in reach
			tree.parentNode.insertBefore(bar, tree);

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.id = 'hc-view-btn';
			btn.className = 'hc-view-btn';
			btn.addEventListener('click', function (ev) {
				ev.preventDefault();
				post({ act: 'view', to: next });
			});
			bar.appendChild(btn);
		}
		var b = document.getElementById('hc-view-btn');
		if (!b) { return; }
		// with nothing hidden the button has nothing to reveal
		if (cats.length === 0) {
			bar.style.display = 'none';
			return;
		}
		bar.style.display = '';
		var l = LABELS[view] || LABELS.hidden;
		b.textContent = l.text;
		b.title = l.title;
		b.setAttribute('data-hc-state', view);
	}

	// ---- 6. the sidebar "Hidden (N)" section ------------------------------
	//
	// Doubles as the list of what is hidden and the fastest way to unhide one
	// thing. Rendered in every view, because "reveal" should not cost a page.
	function buildHiddenSection() {
		if (cats.length === 0) { return; }
		var tree = document.querySelector('nav#sidebar .tree') || document.querySelector('.tree');
		if (!tree) { return; }
		if (document.getElementById('hc-hidden-tree')) { return; }

		var li = document.createElement('li');
		li.id = 'hc-hidden-tree';
		li.className = 'tree-folder category hc-tree';

		var title = document.createElement('a');
		title.className = 'tree-folder-title';
		title.href = '#';
		title.setAttribute('role', 'button');
		title.setAttribute('aria-expanded', view === 'hidden' ? 'false' : 'true');
		title.innerHTML = '<button class="dropdown-toggle" type="button" title="Expand">'
			+ '<span class="hc-caret">&#9662;</span></button>'
			+ '<span class="title">Hidden (' + cats.length + ')</span>';

		var chip = document.createElement('span');
		chip.className = 'hc-chip';
		chip.textContent = HC.mode === 'strict' ? 'enforced' : 'visual';
		chip.title = HC.mode === 'strict'
			? 'Feeds in these categories have FreshRSS\'s "show in its category" visibility.'
			: 'Nothing is written to your feeds; this is filtered in the browser.';
		title.appendChild(chip);

		var ul = document.createElement('ul');
		ul.className = 'tree-folder-items';
		ul.style.display = view === 'hidden' ? 'none' : '';

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
			btn.title = 'Unhide this category (it goes back to normal)';
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

	// ---- 7. the banner: says the stream is filtered, one click out --------
	function buildBanner() {
		if (view !== 'only') { return; }
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
		off.textContent = 'Hide them again';
		off.addEventListener('click', function () {
			post({ act: 'view', to: 'hidden' });
		});
		bar.appendChild(off);
		stream.parentNode.insertBefore(bar, stream);
	}


	// ---- 8. keep markers correct as the page changes ----------------------
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
		buildButton();
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
