/* Messages between members (2.67): /account/messages/. Draws the list and the open
   conversation from the page's data, then keeps them up to date by asking the site
   every few seconds (faster while a conversation is open and the tab is in view). */
(function () {
	'use strict';
	var el = document.getElementById('y4-dm-data'), root = document.querySelector('[data-dm]');
	if (!el || !root) return;
	var D = JSON.parse(el.textContent), T = D.T;
	var list = root.querySelector('[data-dm-list]'), main = root.querySelector('[data-dm-main]');
	var inbox = D.inbox || [], cur = null, lastId = 0, pollT = 0, inboxT = 0, busy = false, pend = 0;
	var coarse = window.matchMedia && matchMedia('(pointer: coarse)').matches;

	/* ---------- little helpers ---------- */
	var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
	var fmt = function (s, v) { return String(s).replace('%s', v).replace('%d', v); };
	var svg = function (d, s) { return '<svg viewBox="0 0 24 24" width="' + (s || 20) + '" height="' + (s || 20) + '" fill="currentColor" aria-hidden="true"><path d="' + d + '"/></svg>'; };
	var I = {
		back: 'M20 11H7.8l5.6-5.6L12 4l-8 8 8 8 1.4-1.4L7.8 13H20v-2Z',
		send: 'M2 21 23 12 2 3v7l15 2-15 2v7Z',
		dots: 'M12 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm0 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm0 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z',
		chat: 'M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2ZM7 9h10v2H7V9Zm6 5H7v-2h6v2Zm4-6H7V6h10v2Z',
		user: 'M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.4 0-8 2.2-8 5v3h16v-3c0-2.8-3.6-5-8-5Z',
		block: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20ZM4 12a8 8 0 0 1 12.9-6.3L5.7 16.9A7.9 7.9 0 0 1 4 12Zm8 8a7.9 7.9 0 0 1-4.9-1.7L18.3 7.1A8 8 0 0 1 12 20Z',
		trash: 'M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12ZM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4Z',
		flag: 'M14.4 6 14 4H5v17h2v-7h5.6l.4 2h7V6h-5.6Z',
		undo: 'M12.5 8c-2.65 0-5.05 1-6.9 2.6L2 7v9h9l-3.62-3.62A7.98 7.98 0 0 1 20.36 16l2.37-.78A10.5 10.5 0 0 0 12.5 8Z'
	};
	var api = function (path, data, method) {
		var opt = { method: method || (data ? 'POST' : 'GET'), credentials: 'same-origin', headers: { 'X-WP-Nonce': D.nonce } };
		if (data && opt.method === 'POST') { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
		var url = D.api + path + (data && opt.method === 'GET' ? '?' + new URLSearchParams(data).toString() : '');
		return fetch(url, opt).then(function (r) { return r.json().then(function (j) { if (!r.ok) { var e = new Error(j && (j.error || j.message) || T.error); e.data = j; throw e; } return j; }); });
	};
	var av = function (w, size) {
		if (w.pic) return '<img class="dm-av" src="' + esc(w.pic) + '" alt="" width="' + size + '" height="' + size + '" loading="lazy">';
		var h = +w.hue || 0, l = Array.from(String(w.name || w.login || '?'))[0].toUpperCase();
		return '<span class="dm-av" style="width:' + size + 'px;height:' + size + 'px;font-size:' + Math.round(size * .44) + 'px;background:linear-gradient(135deg,hsl(' + h + ',70%,52%),hsl(' + ((h + 40) % 360) + ',65%,38%))" aria-hidden="true">' + esc(l) + '</span>';
	};
	var lang = D.lang || undefined;
	var day = function (t) { var d = new Date(t * 1000); return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime(); };
	var clock = function (t) { return new Date(t * 1000).toLocaleTimeString(lang, { hour: '2-digit', minute: '2-digit' }); };
	var dayName = function (t) {
		var today = day(Date.now() / 1000), d = day(t);
		if (d === today) return T.today;
		if (today - d === 864e5) return T.yesterday;
		return new Date(t * 1000).toLocaleDateString(lang, { weekday: 'long', day: 'numeric', month: 'long', year: new Date(t * 1000).getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
	};
	var short = function (t) {
		var s = Date.now() / 1000 - t;
		if (s < 60) return T.now;
		if (day(t) === day(Date.now() / 1000)) return clock(t);
		if (s < 6 * 864e2) return new Date(t * 1000).toLocaleDateString(lang, { weekday: 'short' });
		return new Date(t * 1000).toLocaleDateString(lang, { day: 'numeric', month: 'short' });
	};

	/* ---------- the list ---------- */
	var drawList = function () {
		if (!inbox.length) {
			list.innerHTML = '<div class="dm-none">' + svg(I.chat, 40) + '<b>' + esc(T.empty) + '</b><span>' + esc(T.emptyWhy) + '</span></div>';
			return;
		}
		list.innerHTML = inbox.map(function (x) {
			var on = cur && cur.t === x.t;
			return '<a class="dm-row' + (on ? ' is-on' : '') + (x.unread ? ' is-new' : '') + '" href="' + esc(D.page) + '?t=' + x.t + '" data-t="' + x.t + '" role="listitem">'
				+ '<span class="dm-row-av">' + av(x.with, 48) + (x.with.online ? '<i class="dm-dot" title="' + esc(x.with.seen) + '"></i>' : '') + '</span>'
				+ '<span class="dm-row-t"><span class="dm-row-top"><b>' + esc(x.with.name) + '</b><time>' + esc(short(x.at)) + '</time></span>'
				+ '<span class="dm-row-last">' + (x.mine ? '<span class="dm-you">' + esc(T.you) + '</span>' : '') + esc(x.text) + '</span></span>'
				+ (x.unread ? '<i class="dm-new" aria-label="new"></i>' : '') + '</a>';
		}).join('');
	};
	list.addEventListener('click', function (e) {
		var a = e.target.closest('[data-t]');
		if (!a || e.ctrlKey || e.metaKey) return;
		e.preventDefault();
		open({ t: +a.getAttribute('data-t') }, true);
	});

	/* ---------- a conversation ---------- */
	var none = function () {
		cur = null; lastId = 0;
		root.classList.remove('is-chat');
		main.innerHTML = '<div class="dm-none dm-pick">' + svg(I.chat, 46) + '<b>' + esc(T.pick) + '</b><span>' + esc(T.pickWhy) + '</span></div>';
		drawList();
	};
	var head = function (v) {
		var w = v.with;
		return '<header class="dm-head"><button type="button" class="dm-ico dm-back" data-act="back" aria-label="' + esc(T.back) + '">' + svg(I.back) + '</button>'
			+ '<a class="dm-who" href="' + esc(w.url) + '">' + av(w, 40) + '<span><b>' + esc(w.name) + '</b><small' + (w.online ? ' class="is-on"' : '') + '>' + esc(w.login ? '@' + w.login + ' · ' + w.seen : '') + '</small></span></a>'
			+ '<div class="dm-menu-w"><button type="button" class="dm-ico" data-act="menu" aria-haspopup="true" aria-label="' + esc(T.more) + '">' + svg(I.dots) + '</button>'
			+ '<div class="dm-menu" hidden><a href="' + esc(w.url) + '">' + svg(I.user, 18) + esc(T.profile) + '</a>'
			+ '<button type="button" data-act="block">' + svg(I.block, 18) + esc(v.blocked ? T.unblock : T.block) + '</button>'
			+ (v.t ? '<button type="button" data-act="leave" class="is-bad">' + svg(I.trash, 18) + esc(T.leave) + '</button>' : '') + '</div></div></header>';
	};
	var bubble = function (m) {
		return '<div class="dm-msg' + (m.mine ? ' is-mine' : '') + (m.gone ? ' is-gone' : '') + (m.pending ? ' is-pending' : '') + (m.failed ? ' is-failed' : '') + '" data-id="' + m.id + '" data-at="' + m.at + '">'
			+ '<div class="dm-b">' + (m.gone ? '<i>' + svg(I.undo, 14) + esc(T.unsent) + '</i>' : m.html) + '</div>'
			+ (m.gone || m.pending ? '' : '<button type="button" class="dm-mact" data-act="msg" aria-label="' + esc(T.more) + '">' + svg(I.dots, 16) + '</button>')
			+ '<time>' + esc(m.failed ? T.failed : m.pending ? T.sending : clock(m.at)) + '</time></div>';
	};
	var drawMsgs = function (msgs, where) {
		var box = main.querySelector('[data-msgs]');
		if (!box) return;
		var html = '', prevDay = null, items = box.querySelectorAll('.dm-msg[data-at]');
		if (where === 'end' && items.length) prevDay = day(+items[items.length - 1].getAttribute('data-at'));
		msgs.forEach(function (m) {
			var d = day(m.at);
			if (d !== prevDay) { html += '<div class="dm-day"><span>' + esc(dayName(m.at)) + '</span></div>'; prevDay = d; }
			html += bubble(m);
		});
		if (where === 'start') {
			var first = box.querySelector('.dm-day');
			if (first && msgs.length && day(msgs[msgs.length - 1].at) === day(+box.querySelector('.dm-msg').getAttribute('data-at'))) first.remove();
			var h = box.scrollHeight;
			box.querySelector('[data-older-w]').insertAdjacentHTML('afterend', html);
			box.scrollTop = box.scrollHeight - h;
		} else {
			var near = box.scrollHeight - box.scrollTop - box.clientHeight < 140;
			box.insertAdjacentHTML('beforeend', html);
			if (near || where === 'first' || msgs.some(function (m) { return m.mine; })) box.scrollTop = box.scrollHeight;
		}
		group(box);
	};
	/* Runs of messages from one side within 5 minutes share their corners and one time. */
	var group = function (box) {
		box.querySelectorAll('.dm-seen').forEach(function (x) { x.remove(); });
		var all = box.querySelectorAll('.dm-msg');
		for (var i = 0; i < all.length; i++) {
			var m = all[i], n = all[i + 1], p = all[i - 1];
			var same = function (a, b) { return a && b && a.classList.contains('is-mine') === b.classList.contains('is-mine') && Math.abs(+a.getAttribute('data-at') - +b.getAttribute('data-at')) < 300 && a.nextElementSibling === b; };
			m.classList.toggle('is-cont', same(p, m));
			m.classList.toggle('has-next', same(m, n));
		}
		seen();
	};
	/* "Seen" (or "Sent") under my last message, when it is the last one. */
	var seen = function () {
		var box = main.querySelector('[data-msgs]');
		if (!box || !cur) return;
		box.querySelectorAll('.dm-seen').forEach(function (x) { x.remove(); });
		var all = box.querySelectorAll('.dm-msg'), last = all[all.length - 1];
		if (!last || !last.classList.contains('is-mine') || /is-(pending|failed|gone)/.test(last.className)) return;
		last.insertAdjacentHTML('afterend', '<div class="dm-seen">' + esc(+last.getAttribute('data-id') <= cur.seen ? T.seen : T.sent) + '</div>');
	};
	var composer = function (v) {
		if (!v.can) return '<div class="dm-cant">' + svg(I.block, 18) + '<span>' + esc(v.why) + '</span>' + (v.blocked ? '<button type="button" class="dm-link" data-act="block">' + esc(T.unblock) + '</button>' : '') + '</div>';
		return '<form class="dm-form" data-form><textarea rows="1" maxlength="2000" placeholder="' + esc(T.write) + '" aria-label="' + esc(T.write) + '" data-text></textarea>'
			+ '<button type="submit" class="dm-send" aria-label="' + esc(T.send) + '" disabled>' + svg(I.send, 22) + '</button><small class="dm-left" data-left hidden></small></form>';
	};
	var draw = function (v) {
		var w = v.with;
		main.innerHTML = head(v)
			+ '<div class="dm-msgs" data-msgs><div data-older-w>' + (v.older ? '<button type="button" class="dm-older" data-act="older">' + esc(T.older) + '</button>' : '') + '</div>'
			+ (v.msgs.length ? '' : '<div class="dm-hello" data-hello>' + av(w, 72) + '<b>' + esc(fmt(T.hello, w.name)) + '</b><span>' + esc(T.helloWhy) + '</span></div>') + '</div>'
			+ composer(v);
		drawMsgs(v.msgs, 'first');
		var ta = main.querySelector('[data-text]');
		if (ta && !coarse) ta.focus({ preventScroll: true });
	};
	var open = function (q, push) {
		root.classList.add('is-chat');
		main.innerHTML = '<div class="dm-load"><i></i></div>';
		return api('thread', q, 'GET').then(function (v) { show(v, push); }).catch(function (e) { main.innerHTML = '<div class="dm-none"><b>' + esc(e.message) + '</b></div>'; });
	};
	var show = function (v, push) {
		cur = v;
		lastId = v.msgs.length ? v.msgs[v.msgs.length - 1].id : 0;
		draw(v);
		var row = inbox.filter(function (x) { return x.t === v.t; })[0];
		if (row) row.unread = false;
		drawList();
		badge(inbox.filter(function (x) { return x.unread; }).length);
		if (push) history.pushState({ t: v.t, to: v.t ? '' : v.with.login }, '', D.page + (v.t ? '?t=' + v.t : '?to=' + encodeURIComponent(v.with.login)));
		document.title = v.with.name + ' · ' + T.title;
		schedule();
	};

	/* ---------- sending ---------- */
	main.addEventListener('input', function (e) {
		if (!e.target.matches('[data-text]')) return;
		var ta = e.target, f = ta.form;
		ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
		f.querySelector('.dm-send').disabled = !ta.value.trim();
		var left = 2000 - ta.value.length, l = f.querySelector('[data-left]');
		l.hidden = left > 200; l.textContent = fmt(T.chars, left);
	});
	main.addEventListener('keydown', function (e) {
		if (e.target.matches('[data-text]') && e.key === 'Enter' && !e.shiftKey && !coarse && !e.isComposing) { e.preventDefault(); e.target.form.requestSubmit ? e.target.form.requestSubmit() : e.target.form.dispatchEvent(new Event('submit', { cancelable: true })); }
	});
	main.addEventListener('submit', function (e) {
		if (!e.target.matches('[data-form]')) return;
		e.preventDefault();
		var ta = e.target.querySelector('[data-text]'), text = ta.value.trim();
		if (!text || !cur) return;
		ta.value = ''; ta.style.height = 'auto'; e.target.querySelector('.dm-send').disabled = true;
		post(text);
	});
	var post = function (text, retry) {
		var id = 'p' + (++pend), hello = main.querySelector('[data-hello]');
		if (hello) hello.remove();
		var tmp = { id: id, mine: true, at: Math.floor(Date.now() / 1000), html: esc(text).replace(/\n/g, '<br>'), pending: true };
		if (retry) retry.remove();
		drawMsgs([tmp], 'end');
		api('send', cur.t ? { t: cur.t, text: text } : { to: cur.with.login, text: text }).then(function (r) {
			var node = main.querySelector('[data-id="' + id + '"]');
			if (!cur.t) { cur.t = r.thread; history.replaceState({ t: r.thread }, '', D.page + '?t=' + r.thread); main.querySelector('.dm-head').outerHTML = head(cur); }
			if (node) node.outerHTML = bubble(r.msg);
			lastId = Math.max(lastId, r.msg.id);
			group(main.querySelector('[data-msgs]'));
			refreshList();
		}).catch(function (err) {
			var node = main.querySelector('[data-id="' + id + '"]');
			if (node) { node.classList.remove('is-pending'); node.classList.add('is-failed'); node.querySelector('time').textContent = err.message || T.failed; node.setAttribute('data-retry', text); }
		});
	};

	/* ---------- menus, unsend, report, block ---------- */
	var closeMenus = function () { main.querySelectorAll('.dm-menu').forEach(function (m) { m.hidden = true; }); main.querySelectorAll('.dm-msg-menu').forEach(function (m) { m.remove(); }); };
	document.addEventListener('click', function (e) { if (!e.target.closest('.dm-menu-w, .dm-msg-menu, [data-act="msg"]')) closeMenus(); });
	main.addEventListener('click', function (e) {
		var failed = e.target.closest('.dm-msg.is-failed');
		if (failed) { post(failed.getAttribute('data-retry'), failed); return; }
		var tap = e.target.closest('.dm-b');
		if (tap && coarse && !e.target.closest('a')) { // phones: a tap on a message shows its ⋮
			var mm = tap.closest('.dm-msg'), was = mm.classList.contains('is-act');
			main.querySelectorAll('.dm-msg.is-act').forEach(function (x) { x.classList.remove('is-act'); });
			mm.classList.toggle('is-act', !was);
			return;
		}
		var b = e.target.closest('[data-act]');
		if (!b) return;
		var act = b.getAttribute('data-act');
		if (act === 'back') { history.pushState({}, '', D.page); none(); }
		else if (act === 'menu') { var m = b.nextElementSibling; var was = m.hidden; closeMenus(); m.hidden = !was; }
		else if (act === 'older') {
			var firstMsg = main.querySelector('.dm-msg[data-id]');
			b.disabled = true;
			api('thread', { t: cur.t, before: firstMsg.getAttribute('data-id') }, 'GET').then(function (v) {
				var w = main.querySelector('[data-older-w]'); w.innerHTML = v.older ? '<button type="button" class="dm-older" data-act="older">' + esc(T.older) + '</button>' : '';
				drawMsgs(v.msgs, 'start');
			});
		}
		else if (act === 'block') {
			closeMenus();
			if (!cur.blocked && !confirm(fmt(T.blockQ, cur.with.name))) return;
			api('block', cur.t ? { t: cur.t, on: cur.blocked ? 0 : 1 } : { to: cur.with.login, on: 1 }).then(function () { open({ t: cur.t || '', to: cur.t ? '' : cur.with.login }); });
		}
		else if (act === 'leave') {
			closeMenus();
			if (!confirm(T.leaveQ)) return;
			api('leave', { t: cur.t }).then(function () { inbox = inbox.filter(function (x) { return x.t !== cur.t; }); history.pushState({}, '', D.page); none(); });
		}
		else if (act === 'msg') {
			var msg = b.closest('.dm-msg'), had = msg.querySelector('.dm-msg-menu');
			closeMenus();
			if (had) return;
			msg.insertAdjacentHTML('beforeend', '<div class="dm-msg-menu">' + (msg.classList.contains('is-mine')
				? '<button type="button" data-act="unsend">' + svg(I.undo, 16) + esc(T.unsend) + '</button>'
				: '<button type="button" data-act="report">' + svg(I.flag, 16) + esc(T.report) + '</button>') + '</div>');
		}
		else if (act === 'unsend') {
			var um = b.closest('.dm-msg'); closeMenus();
			if (!confirm(T.unsendQ)) return;
			api('unsend', { id: +um.getAttribute('data-id') }).then(function () { gone([+um.getAttribute('data-id')]); refreshList(); });
		}
		else if (act === 'report') { var rm = b.closest('.dm-msg'); closeMenus(); report(+rm.getAttribute('data-id')); }
	});
	var gone = function (ids) {
		ids.forEach(function (id) {
			var n = main.querySelector('.dm-msg[data-id="' + id + '"]');
			if (n && !n.classList.contains('is-gone')) n.outerHTML = bubble({ id: id, mine: n.classList.contains('is-mine'), at: +n.getAttribute('data-at'), gone: 1 });
		});
		var box = main.querySelector('[data-msgs]'); if (box) group(box);
	};
	var report = function (id) {
		var dlg = document.createElement('dialog');
		dlg.className = 'dm-dialog';
		dlg.innerHTML = '<form method="dialog"><h2>' + esc(T.reportT) + '</h2><p>' + esc(T.reportWhy) + '</p>'
			+ ['spam', 'scam', 'abuse', 'other'].map(function (k, i) { return '<label class="dm-opt"><input type="radio" name="why" value="' + k + '"' + (i ? '' : ' checked') + '> <span>' + esc(T[k]) + '</span></label>'; }).join('')
			+ '<label class="dm-opt dm-opt-b"><input type="checkbox" name="block" value="1" checked> <span>' + esc(T.alsoBlock) + '</span></label>'
			+ '<div class="dm-dialog-acts"><button value="cancel" class="dm-btn-g">' + esc(T.cancel) + '</button><button value="ok" class="dm-btn-r">' + esc(T.report) + '</button></div></form>';
		document.body.appendChild(dlg);
		dlg.addEventListener('close', function () {
			if (dlg.returnValue === 'ok') {
				var f = dlg.querySelector('form'), blk = f.block.checked;
				api('report', { id: id, why: f.querySelector('[name=why]:checked').value, block: blk ? 1 : 0 }).then(function () { alert(T.reported); if (blk) open({ t: cur.t }); });
			}
			dlg.remove();
		});
		dlg.showModal ? dlg.showModal() : dlg.setAttribute('open', '');
	};

	/* ---------- keeping up to date ---------- */
	var refreshList = function () {
		return api('inbox', null, 'GET').then(function (r) { inbox = r.list; drawList(); badge(r.unread); }).catch(function () {});
	};
	var badge = function (n) {
		document.querySelectorAll('[data-dm-count]').forEach(function (x) { x.textContent = n > 99 ? '99+' : n; x.hidden = !n; });
		document.querySelectorAll('.h-acct-btn.is-in').forEach(function (x) { x.classList.toggle('has-msg', n > 0); });
		try { sessionStorage.setItem('y4apk_dm_n', JSON.stringify({ n: n, t: Date.now() })); } catch (e) {}
	};
	var poll = function () {
		if (!cur || !cur.t || busy) return Promise.resolve();
		busy = true;
		return api('thread', { t: cur.t, after: lastId }, 'GET').then(function (v) {
			if (!cur || v.t !== cur.t) return;
			var fresh = v.msgs.filter(function (m) { return !main.querySelector('.dm-msg[data-id="' + m.id + '"]'); });
			if (fresh.length) { var h = main.querySelector('[data-hello]'); if (h) h.remove(); drawMsgs(fresh, 'end'); }
			if (v.msgs.length) lastId = v.msgs[v.msgs.length - 1].id;
			if (v.gone && v.gone.length) gone(v.gone);
			var changed = cur.seen !== v.seen || cur.can !== v.can;
			cur.seen = v.seen; cur.with = v.with;
			if (cur.can !== v.can) { cur.can = v.can; cur.why = v.why; cur.blocked = v.blocked; var c = main.querySelector('.dm-form, .dm-cant'); if (c) c.outerHTML = composer(cur); }
			if (changed) seen();
			if (fresh.some(function (m) { return !m.mine; })) refreshList();
		}).catch(function () {}).then(function () { busy = false; });
	};
	var schedule = function () {
		clearTimeout(pollT); clearTimeout(inboxT);
		var hidden = document.hidden;
		var tick = function () { poll().then(function () { pollT = setTimeout(tick, document.hidden ? 20000 : 3000); }); };
		var tickList = function () { refreshList().then(function () { inboxT = setTimeout(tickList, document.hidden ? 60000 : 15000); }); };
		pollT = setTimeout(tick, hidden ? 20000 : 3000);
		inboxT = setTimeout(tickList, hidden ? 60000 : 15000);
	};
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); refreshList(); } schedule(); });
	window.addEventListener('popstate', function () {
		var p = new URLSearchParams(location.search);
		if (p.get('t')) open({ t: p.get('t') });
		else if (p.get('to')) open({ to: p.get('to') });
		else none();
	});

	/* ---------- start ---------- */
	drawList();
	if (D.open) show(D.open, false); else none();
	if (!D.open) schedule();
	badge(inbox.filter(function (x) { return x.unread; }).length);
})();
