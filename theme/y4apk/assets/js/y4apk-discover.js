/*
 * Y4APK ▸ Write with AI ▸ Add from Google Play: finds apps the site has no
 * post for, shows each with its Google Play details, and adds the ticked ones
 * one after another — the post is made, then written by Write with AI (all
 * parts, category included). The AI busy: the post waits first in the queue.
 */
(function () {
	'use strict';
	var W = window.y4apkWriter, D = window.y4apkDiscover;
	if (!W || !D) {
		return;
	}
	var T = D.text, ALL = 31, NO_ARTICLE = 30, running = false;

	function $(sel, root) {
		return (root || document).querySelector(sel);
	}
	function $$(sel, root) {
		return [].slice.call((root || document).querySelectorAll(sel));
	}
	function fmt(s) {
		var args = [].slice.call(arguments, 1);
		return s.replace(/%(\d)\$s/g, function (_, n) { return args[n - 1]; }).replace('%s', args[0]);
	}
	function el(tag, attrs, text) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
		if (text !== undefined) {
			e.textContent = text;
		}
		return e;
	}
	function ajax(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', W.nonce);
		Object.keys(data || {}).forEach(function (k) {
			[].concat(data[k]).forEach(function (v) { body.append(Array.isArray(data[k]) ? k + '[]' : k, v); });
		});
		return fetch(W.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j || !j.success) {
					var e = new Error((j && j.data && j.data.message) || 'error');
					e.data = j && j.data;
					throw e;
				}
				return j.data;
			});
	}

	var grid = $('#ad-results'), bar = $('.ad-bar'), summary = $('.ad-summary'), status = $('.ad-status');

	function card(pkg) {
		var c = el('div', { 'class': 'ad-card', 'data-package': pkg });
		c.appendChild(el('input', { type: 'checkbox', 'class': 'ad-pick', value: pkg, 'aria-label': pkg }));
		c.appendChild(el('span', { 'class': 'ad-ph' }));
		var body = el('div');
		body.appendChild(el('h3', {}, pkg));
		body.appendChild(el('div', { 'class': 'ad-meta' }, '…'));
		var actions = el('div', { 'class': 'ad-actions' });
		var add = el('button', { type: 'button', 'class': 'button ad-add', disabled: 'disabled' }, T.add);
		actions.appendChild(add);
		actions.appendChild(el('span', { 'class': 'ad-msg' }));
		body.appendChild(actions);
		c.appendChild(body);
		return c;
	}

	function fill(c, d) {
		var h = $('h3', c), meta = $('.ad-meta', c);
		if (d.missing) {
			meta.textContent = T.missing;
			$('.ad-pick', c).disabled = true;
			return;
		}
		if (d.icon) {
			var img = el('img', { src: d.icon, alt: '', loading: 'lazy', width: 56, height: 56 });
			c.replaceChild(img, $('.ad-ph', c));
		}
		h.textContent = '';
		h.appendChild(el('a', { href: d.url, target: '_blank', rel: 'noopener' }, d.title));
		meta.textContent = [d.developer, d.genre, d.rating ? '★ ' + d.rating + (d.votes ? ' (' + Number(d.votes).toLocaleString() + ')' : '') : '', d.version ? 'v' + d.version : '']
			.filter(Boolean).join(' · ');
		if (d.maybe) {
			var m = el('div', { 'class': 'ad-maybe' }, fmt(T.maybe, '') );
			m.appendChild(el('a', { href: d.maybe.edit, target: '_blank' }, d.maybe.title));
			meta.parentNode.insertBefore(m, meta.nextSibling);
		}
		$('.ad-add', c).disabled = false;
	}

	// Details for every card, four at a time.
	function details(cards) {
		var i = 0;
		function next() {
			if (i >= cards.length) {
				return Promise.resolve();
			}
			var c = cards[i++];
			return ajax('y4apk_discover_info', { 'package': c.dataset.package })
				.then(function (d) { fill(c, d); }, function (e) { $('.ad-meta', c).textContent = e.message; })
				.then(next);
		}
		return Promise.all([next(), next(), next(), next()]);
	}

	$('#ad-form').addEventListener('submit', function (e) {
		e.preventDefault();
		var q = $('#ad-q').value.trim(), list = $('#ad-list').value;
		grid.innerHTML = '';
		bar.hidden = true;
		summary.textContent = T.searching;
		ajax('y4apk_discover_find', { q: list ? '' : q, list: list }).then(function (r) {
			summary.textContent = r['new'].length ? fmt(T.found, r['new'].length, r.have.length) : T.none;
			var cards = r['new'].map(function (p) { var c = card(p); grid.appendChild(c); return c; });
			if (r.have.length) {
				var d = el('details', { 'class': 'ad-have' });
				d.appendChild(el('summary', {}, T.have + ' (' + r.have.length + ')'));
				var ul = el('ul');
				r.have.forEach(function (h) {
					var li = el('li');
					li.appendChild(el('a', { href: h[3], target: '_blank' }, h[2] || h[0]));
					li.appendChild(document.createTextNode(' · ' + h[0]));
					ul.appendChild(li);
				});
				d.appendChild(ul);
				grid.parentNode.insertBefore(d, grid.nextSibling);
			}
			bar.hidden = !cards.length;
			return details(cards);
		}).catch(function (e) {
			summary.textContent = e.message;
		});
		$$('.ad-have').forEach(function (x) { x.remove(); });
	});

	function setMsg(c, text, kind) {
		var m = $('.ad-msg', c);
		m.className = 'ad-msg' + (kind ? ' is-' + kind : '');
		m.textContent = text;
	}

	// One app: the post, then Write with AI; the AI busy → first in the queue.
	function addOne(c) {
		var btn = $('.ad-add', c), publish = $('#ad-publish').checked ? 1 : 0;
		btn.disabled = true;
		$('.ad-pick', c).disabled = true;
		setMsg(c, T.adding);
		return ajax('y4apk_discover_add', { 'package': c.dataset.package, publish: publish }).then(function (r) {
			setMsg(c, T.writing);
			return ajax('y4apk_writer_write', { id: r.id, parts: ALL, keep: 1 }).then(function (w) {
				if (w.ok) {
					return done(c, r, w.message, 'good');
				}
				if (w.retry) {
					return ajax('y4apk_writer_enqueue', { ids: [r.id], parts: ALL, front: 1, keep: 1 }).then(function () { return done(c, r, T.queued, 'good'); });
				}
				if (['key', 'credit', 'model'].indexOf(w.code) >= 0) {
					return ajax('y4apk_writer_write', { id: r.id, parts: NO_ARTICLE, keep: 1 }).then(function () { return done(c, r, T.noai + ' (' + w.message + ')', 'bad'); });
				}
				return done(c, r, w.message, 'bad');
			});
		}).catch(function (e) {
			btn.disabled = false;
			$('.ad-pick', c).disabled = false;
			setMsg(c, e.message, 'bad');
			return false;
		});
	}

	function done(c, r, message, kind) {
		c.classList.add(kind === 'good' ? 'is-good' : 'is-bad');
		$('.ad-add', c).textContent = '✓ ' + T.added;
		$('.ad-pick', c).checked = false;
		setMsg(c, message, kind);
		var a = el('a', { href: r.edit, target: '_blank' }, T.edit);
		$('.ad-actions', c).insertBefore(a, $('.ad-msg', c));
		return true;
	}

	grid.addEventListener('click', function (e) {
		var b = e.target.closest('.ad-add');
		if (b && !running) {
			addOne(b.closest('.ad-card'));
		}
	});

	$('#ad-all').addEventListener('change', function () {
		var on = this.checked;
		$$('.ad-pick', grid).forEach(function (x) { if (!x.disabled) { x.checked = on; } });
	});

	$('#ad-add-selected').addEventListener('click', function () {
		if (running) {
			return;
		}
		var cards = $$('.ad-pick:checked', grid).map(function (x) { return x.closest('.ad-card'); });
		if (!cards.length) {
			alert(T.pick);
			return;
		}
		running = true;
		var n = 0, ok = 0;
		cards.reduce(function (p, c) {
			return p.then(function () {
				n++;
				status.textContent = fmt(T.progress, n, cards.length, $('h3', c).textContent);
				c.scrollIntoView({ block: 'nearest' });
				return addOne(c).then(function (r) { ok += r ? 1 : 0; });
			});
		}, Promise.resolve()).then(function () {
			running = false;
			status.textContent = fmt(T.done, ok);
		});
	});
})();
