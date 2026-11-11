/* Messages between members (2.67): /account/messages/. Draws the list and the open
   conversation from the page's data, then keeps them up to date by asking the site
   every few seconds (faster while a conversation is open and the tab is in view).
   2.73: photos and voice messages, replies (swipe a message to the right on a phone,
   or its ↩), "typing…", and steadier: messages in their order whatever comes back
   first, a request that hangs gives up, a sign-in key that ran out is renewed, a
   message sent again is not saved twice, and asking slows down while offline. */
(function () {
	'use strict';
	var el = document.getElementById('y4-dm-data'), root = document.querySelector('[data-dm]');
	if (!el || !root) return;
	var D = JSON.parse(el.textContent), T = D.T, M = D.media || {};
	var list = root.querySelector('[data-dm-list]'), main = root.querySelector('[data-dm-main]');
	var inbox = D.inbox || [], cur = null, lastId = 0, pollT = 0, inboxT = 0, busy = false, pend = 0, openSeq = 0, fails = 0;
	var reply = null, photo = null, rec = null, typingAt = 0, typingSay = null, otherTyping = false;
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
		undo: 'M12.5 8c-2.65 0-5.05 1-6.9 2.6L2 7v9h9l-3.62-3.62A7.98 7.98 0 0 1 20.36 16l2.37-.78A10.5 10.5 0 0 0 12.5 8Z',
		reply: 'M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11Z',
		photo: 'M21 19V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2ZM8.5 13.5l2.5 3 3.5-4.5 4.5 6H5l3.5-4.5Z',
		mic: 'M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Zm5.3-3a5.3 5.3 0 0 1-10.6 0H5a7 7 0 0 0 6 6.9V21h2v-3.1A7 7 0 0 0 19 11h-1.7Z',
		play: 'M8 5v14l11-7L8 5Z',
		pause: 'M6 19h4V5H6v14Zm8-14v14h4V5h-4Z',
		x: 'M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12 19 6.4Z',
		copy: 'M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1Zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Zm0 16H8V7h11v14Z'
	};
	var cid = function () { return 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); };

	/* Asks the site. Gives up after a while (a request that hangs no longer stops the checks),
	   and renews the page's sign-in key once when it ran out (a tab left open for a day). */
	var renewing = null;
	var renew = function () {
		if (!renewing) renewing = fetch(D.ajax + '?action=y4apk_dm_nonce', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { if (j && j.success) D.nonce = j.data.nonce; }).then(function () { renewing = null; }, function () { renewing = null; });
		return renewing;
	};
	var call = function (path, data, method, form, again) {
		var ctl = window.AbortController ? new AbortController() : null;
		var opt = { method: method || (data || form ? 'POST' : 'GET'), credentials: 'same-origin', headers: { 'X-WP-Nonce': D.nonce } };
		if (ctl) { opt.signal = ctl.signal; setTimeout(function () { ctl.abort(); }, form ? 120000 : 20000); }
		if (form) opt.body = form;
		else if (data && opt.method === 'POST') { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
		var url = D.api + path + (data && opt.method === 'GET' ? '?' + new URLSearchParams(data).toString() : '');
		return fetch(url, opt).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (r.status === 403 && j && j.code === 'rest_cookie_invalid_nonce' && !again) return renew().then(function () { return call(path, data, method, form, true); });
				if (!r.ok) { var e = new Error(j && (j.error || j.message) || T.error); e.data = j; e.status = r.status; throw e; }
				return j;
			});
		});
	};
	var api = function (path, data, method) { return call(path, data, method); };
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
	var mmss = function (s) { s = Math.max(0, Math.round(s)); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); };
	var box = function () { return main.querySelector('[data-msgs]'); };

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
		cur = null; lastId = 0; openSeq++;
		stopRec(); clearPhoto(); setReply(null);
		root.classList.remove('is-chat');
		main.innerHTML = '<div class="dm-none dm-pick">' + svg(I.chat, 46) + '<b>' + esc(T.pick) + '</b><span>' + esc(T.pickWhy) + '</span></div>';
		drawList();
	};
	var status = function (v) { return v.typing ? T.typing : (v.with.login ? '@' + v.with.login + ' · ' + v.with.seen : ''); };
	var head = function (v) {
		var w = v.with;
		return '<header class="dm-head"><button type="button" class="dm-ico dm-back" data-act="back" aria-label="' + esc(T.back) + '">' + svg(I.back) + '</button>'
			+ '<a class="dm-who" href="' + esc(w.url) + '">' + av(w, 40) + '<span><b>' + esc(w.name) + '</b><small data-status class="' + (v.typing ? 'is-typing' : w.online ? 'is-on' : '') + '">' + esc(status(v)) + '</small></span></a>'
			+ '<div class="dm-menu-w"><button type="button" class="dm-ico" data-act="menu" aria-haspopup="true" aria-label="' + esc(T.more) + '">' + svg(I.dots) + '</button>'
			+ '<div class="dm-menu" hidden><a href="' + esc(w.url) + '">' + svg(I.user, 18) + esc(T.profile) + '</a>'
			+ '<button type="button" data-act="block">' + svg(I.block, 18) + esc(v.blocked ? T.unblock : T.block) + '</button>'
			+ (v.t ? '<button type="button" data-act="leave" class="is-bad">' + svg(I.trash, 18) + esc(T.leave) + '</button>' : '') + '</div></div></header>';
	};
	var who = function (mine) { return mine ? T.youName : (cur ? cur.with.name : ''); };
	var media = function (m) {
		if (m.kind === 'img' && m.src) {
			var w = m.w || 320, h = m.h || 240, r = Math.min(1, 260 / w, 320 / h);
			return '<button type="button" class="dm-img" data-view="' + esc(m.src) + '" aria-label="' + esc(T.photo) + '"><img src="' + esc(m.src) + '" alt="" width="' + Math.round(w * r) + '" height="' + Math.round(h * r) + '" loading="lazy" decoding="async"></button>';
		}
		if (m.kind === 'voice' && m.src) {
			return '<div class="dm-voice" data-voice="' + esc(m.src) + '" data-d="' + (m.d || 0) + '"><button type="button" class="dm-vplay" data-act="play" aria-label="' + esc(T.play) + '">' + svg(I.play, 20) + '</button>'
				+ '<span class="dm-vbar"><i data-vfill></i></span><b class="dm-vt" data-vtime>' + mmss(m.d || 0) + '</b></div>';
		}
		if (m.local) return '<div class="dm-img dm-img-local">' + m.local + '</div>';
		return '';
	};
	var bubble = function (m) {
		var q = m.reply ? '<button type="button" class="dm-q" data-jump="' + m.reply.id + '"><b>' + esc(m.reply.mine ? T.youName : (cur ? cur.with.name : '')) + '</b><span>' + esc(m.reply.text) + '</span></button>' : '';
		var body = m.gone ? '<i>' + svg(I.undo, 14) + esc(T.unsent) + '</i>' : q + media(m) + (m.html ? '<span class="dm-text">' + m.html + '</span>' : '');
		var snip = m.gone ? '' : (m.kind === 'img' ? '📷 ' + T.photo : m.kind === 'voice' ? '🎤 ' + T.voice : '') + (m.html ? (m.kind ? ' · ' : '') + (m.text || tmpText(m.html)) : '');
		return '<div class="dm-msg' + (m.mine ? ' is-mine' : '') + (m.gone ? ' is-gone' : '') + (m.pending ? ' is-pending' : '') + (m.failed ? ' is-failed' : '') + (m.kind && !m.html && !m.reply ? ' is-bare' : '') + '" data-id="' + m.id + '" data-at="' + m.at + '" data-snip="' + esc(snip.slice(0, 90)) + '">'
			+ '<div class="dm-b">' + body + '</div>'
			+ (m.gone || m.pending ? '' : '<span class="dm-acts"><button type="button" class="dm-mact" data-act="reply" aria-label="' + esc(T.reply) + '">' + svg(I.reply, 16) + '</button><button type="button" class="dm-mact" data-act="msg" aria-label="' + esc(T.more) + '">' + svg(I.dots, 16) + '</button></span>')
			+ '<time>' + esc(m.failed ? T.failed : m.pending ? T.sending : clock(m.at)) + '</time></div>';
	};
	var tmpText = function (html) { var d = document.createElement('div'); d.innerHTML = html; return (d.textContent || '').replace(/\s+/g, ' ').trim(); };

	/* Messages go where their number says (one sent and one received at once can come back in any order). */
	var place = function (m) {
		var b = box();
		if (!b) return;
		var have = b.querySelector('.dm-msg[data-id="' + m.id + '"]');
		if (have) { if (!m.pending) have.outerHTML = bubble(m); return; }
		var html = bubble(m), before = null;
		if (typeof m.id === 'number') {
			var all = b.querySelectorAll('.dm-msg[data-id]');
			for (var i = 0; i < all.length; i++) {
				var id = all[i].getAttribute('data-id');
				if (/^p/.test(id) || +id > m.id) { before = all[i]; break; }
			}
		}
		if (before) before.insertAdjacentHTML('beforebegin', html);
		else b.insertAdjacentHTML('beforeend', html);
	};
	var days = function (b) {
		b.querySelectorAll('.dm-day').forEach(function (x) { x.remove(); });
		var prev = null;
		b.querySelectorAll('.dm-msg').forEach(function (m) {
			var d = day(+m.getAttribute('data-at'));
			if (d !== prev) { m.insertAdjacentHTML('beforebegin', '<div class="dm-day"><span>' + esc(dayName(+m.getAttribute('data-at'))) + '</span></div>'); prev = d; }
		});
	};
	var drawMsgs = function (msgs, where) {
		var b = box();
		if (!b || !msgs.length) return;
		var near = b.scrollHeight - b.scrollTop - b.clientHeight < 140, h = b.scrollHeight;
		if (where === 'start') {
			var w = b.querySelector('[data-older-w]'), html = msgs.map(bubble).join('');
			w.insertAdjacentHTML('afterend', html);
		} else {
			msgs.forEach(place);
		}
		days(b); group(b);
		if (where === 'start') b.scrollTop = b.scrollHeight - h;
		else if (near || where === 'first' || msgs.some(function (m) { return m.mine; })) b.scrollTop = b.scrollHeight;
	};
	/* Runs of messages from one side within 5 minutes share their corners and one time. */
	var group = function (b) {
		var all = b.querySelectorAll('.dm-msg');
		var same = function (a, c) { return a && c && a.classList.contains('is-mine') === c.classList.contains('is-mine') && Math.abs(+a.getAttribute('data-at') - +c.getAttribute('data-at')) < 300 && a.nextElementSibling === c; };
		for (var i = 0; i < all.length; i++) {
			all[i].classList.toggle('is-cont', same(all[i - 1], all[i]));
			all[i].classList.toggle('has-next', same(all[i], all[i + 1]));
		}
		seen();
	};
	/* "Seen" (or "Sent") under my last message, when it is the last one; the other side's "typing…" below everything. */
	var seen = function () {
		var b = box();
		if (!b || !cur) return;
		b.querySelectorAll('.dm-seen, .dm-typing').forEach(function (x) { x.remove(); });
		var all = b.querySelectorAll('.dm-msg'), last = all[all.length - 1];
		if (last && last.classList.contains('is-mine') && !/is-(pending|failed|gone)/.test(last.className)) last.insertAdjacentHTML('afterend', '<div class="dm-seen">' + esc(+last.getAttribute('data-id') <= cur.seen ? T.seen : T.sent) + '</div>');
		if (otherTyping) b.insertAdjacentHTML('beforeend', '<div class="dm-typing" aria-label="' + esc(T.typing) + '"><span class="dm-b"><i></i><i></i><i></i></span></div>');
	};
	var composer = function (v) {
		if (!v.can) return '<div class="dm-cant">' + svg(I.block, 18) + '<span>' + esc(v.why) + '</span>' + (v.blocked ? '<button type="button" class="dm-link" data-act="block">' + esc(T.unblock) + '</button>' : '') + '</div>';
		var mm = v.media || {};
		return '<div class="dm-compose" data-compose>'
			+ '<div class="dm-reply" data-replybar hidden></div>'
			+ '<div class="dm-att" data-att hidden></div>'
			+ '<form class="dm-form" data-form>'
			+ (mm.img && M.img ? '<label class="dm-ico dm-pick" title="' + esc(T.addPhoto) + '">' + svg(I.photo, 22) + '<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" data-photo hidden aria-label="' + esc(T.addPhoto) + '"></label>' : '')
			+ '<textarea rows="1" maxlength="2000" placeholder="' + esc(T.write) + '" aria-label="' + esc(T.write) + '" data-text></textarea>'
			+ (mm.voice && M.voice ? '<button type="button" class="dm-send dm-mic" data-act="rec" aria-label="' + esc(T.record) + '">' + svg(I.mic, 22) + '</button>' : '')
			+ '<button type="submit" class="dm-send" data-sendbtn aria-label="' + esc(T.send) + '" disabled' + (mm.voice && M.voice ? ' hidden' : '') + '>' + svg(I.send, 22) + '</button><small class="dm-left" data-left hidden></small></form>'
			+ '<div class="dm-rec" data-rec hidden><button type="button" class="dm-ico dm-rec-x" data-act="rec-x" aria-label="' + esc(T.cancel) + '">' + svg(I.trash, 22) + '</button><span class="dm-rec-t"><i></i><b data-rect>0:00</b><span>' + esc(T.recording) + '</span></span><button type="button" class="dm-send" data-act="rec-send" aria-label="' + esc(T.stopSend) + '">' + svg(I.send, 22) + '</button></div>'
			+ '</div>';
	};
	var draw = function (v) {
		var w = v.with;
		main.innerHTML = head(v)
			+ '<div class="dm-msgs" data-msgs><div data-older-w>' + (v.older ? '<button type="button" class="dm-older" data-act="older">' + esc(T.older) + '</button>' : '') + '</div>'
			+ (v.msgs.length ? '' : '<div class="dm-hello" data-hello>' + av(w, 72) + '<b>' + esc(fmt(T.hello, w.name)) + '</b><span>' + esc(T.helloWhy) + (coarse ? ' ' + esc(T.swipe) : '') + '</span></div>') + '</div>'
			+ composer(v);
		otherTyping = !!v.typing;
		drawMsgs(v.msgs, 'first');
		if (!v.msgs.length) seen();
		var ta = main.querySelector('[data-text]');
		if (ta && !coarse) ta.focus({ preventScroll: true });
	};
	var open = function (q, push) {
		var mine = ++openSeq;
		stopRec(); clearPhoto(); setReply(null);
		root.classList.add('is-chat');
		main.innerHTML = '<div class="dm-load"><i></i></div>';
		return api('thread', q, 'GET').then(function (v) { if (mine === openSeq) show(v, push); }).catch(function (e) { if (mine === openSeq) main.innerHTML = '<div class="dm-none"><b>' + esc(e.message) + '</b></div>'; });
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

	/* ---------- replying ---------- */
	var setReply = function (m) {
		reply = m;
		var bar = main.querySelector('[data-replybar]');
		if (!bar) return;
		bar.hidden = !m;
		bar.innerHTML = m ? svg(I.reply, 18) + '<span><b>' + esc(fmt(T.replyTo, m.mine ? T.yourself : (cur ? cur.with.name : ''))) + '</b><small>' + esc(m.text) + '</small></span><button type="button" class="dm-ico" data-act="reply-x" aria-label="' + esc(T.cancel) + '">' + svg(I.x, 18) + '</button>' : '';
		if (m) { var ta = main.querySelector('[data-text]'); if (ta) ta.focus({ preventScroll: true }); }
	};
	var replyFrom = function (node) { return node ? { id: +node.getAttribute('data-id'), mine: node.classList.contains('is-mine'), text: node.getAttribute('data-snip') || '' } : null; };
	/* A phone: a message swiped to the right is answered; held down, it shows its ↩ and ⋮ (a photo alone has no words to tap). */
	var sw = null, lpT = 0, lpAt = 0;
	main.addEventListener('touchstart', function (e) {
		var m = e.target.closest('.dm-msg');
		clearTimeout(lpT);
		if (!m || /is-(pending|failed)/.test(m.className) || e.touches.length !== 1 || e.target.closest('.dm-voice, .dm-acts')) { sw = null; return; }
		sw = { m: m, x: e.touches[0].clientX, y: e.touches[0].clientY, dx: 0, on: false, moved: false };
		if (!m.classList.contains('is-gone')) lpT = setTimeout(function () {
			if (!sw || sw.m !== m || sw.on || sw.moved) return;
			lpAt = Date.now();
			main.querySelectorAll('.dm-msg.is-act').forEach(function (x) { x.classList.remove('is-act'); });
			m.classList.add('is-act');
			if (navigator.vibrate) { try { navigator.vibrate(10); } catch (er) {} }
		}, 480);
		if (m.classList.contains('is-gone')) sw = null;
	}, { passive: true });
	main.addEventListener('touchmove', function (e) {
		if (!sw) return;
		var dx = e.touches[0].clientX - sw.x, dy = e.touches[0].clientY - sw.y;
		if (Math.abs(dx) > 8 || Math.abs(dy) > 8) { sw.moved = true; clearTimeout(lpT); }
		if (!sw.on) { if (Math.abs(dy) > 12 && Math.abs(dy) > Math.abs(dx)) { sw = null; return; } if (dx > 12 && dx > Math.abs(dy)) sw.on = true; }
		if (!sw.on) return;
		sw.dx = Math.max(0, Math.min(84, dx - 12));
		sw.m.style.transform = 'translateX(' + sw.dx + 'px)';
		sw.m.classList.toggle('is-swipe', sw.dx > 56);
	}, { passive: true });
	var swEnd = function () {
		clearTimeout(lpT);
		if (!sw) return;
		var s = sw; sw = null;
		s.m.style.transition = 'transform .18s'; s.m.style.transform = '';
		setTimeout(function () { s.m.style.transition = ''; s.m.classList.remove('is-swipe'); }, 200);
		if (s.dx > 56) { if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) {} } setReply(replyFrom(s.m)); }
	};
	main.addEventListener('touchend', swEnd);
	main.addEventListener('touchcancel', swEnd);

	/* ---------- typing ---------- */
	var typed = function (has) {
		if (!cur || !cur.t) return;
		if (has && Date.now() - typingAt > 3000) { typingAt = Date.now(); typingSay = 1; poll(); }
		else if (!has && typingAt) { typingAt = 0; typingSay = 0; }
	};
	var setTyping = function (on) {
		if (!cur) return;
		cur.typing = on;
		var st = main.querySelector('[data-status]');
		if (st) { st.textContent = status(cur); st.className = on ? 'is-typing' : cur.with.online ? 'is-on' : ''; }
		if (on !== otherTyping) {
			var b = box(), near = b && b.scrollHeight - b.scrollTop - b.clientHeight < 140;
			otherTyping = on; seen();
			if (b && near) b.scrollTop = b.scrollHeight;
		}
	};

	/* ---------- sending text ---------- */
	var buttons = function () {
		var f = main.querySelector('[data-form]');
		if (!f) return;
		var has = !!f.querySelector('[data-text]').value.trim() || !!photo, mic = f.querySelector('[data-act="rec"]'), snd = f.querySelector('[data-sendbtn]');
		snd.disabled = !has;
		if (mic) { mic.hidden = has; snd.hidden = !has; }
	};
	main.addEventListener('input', function (e) {
		if (!e.target.matches('[data-text]')) return;
		var ta = e.target, f = ta.form;
		ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
		buttons();
		var left = 2000 - ta.value.length, l = f.querySelector('[data-left]');
		l.hidden = left > 200; l.textContent = fmt(T.chars, left);
		typed(!!ta.value.trim());
	});
	main.addEventListener('keydown', function (e) {
		if (e.target.matches('[data-text]') && e.key === 'Enter' && !e.shiftKey && !coarse && !e.isComposing) { e.preventDefault(); e.target.form.requestSubmit ? e.target.form.requestSubmit() : e.target.form.dispatchEvent(new Event('submit', { cancelable: true })); }
		if (e.key === 'Escape' && reply) setReply(null);
	});
	main.addEventListener('submit', function (e) {
		if (!e.target.matches('[data-form]')) return;
		e.preventDefault();
		var ta = e.target.querySelector('[data-text]'), text = ta.value.trim();
		if ((!text && !photo) || !cur) return;
		ta.value = ''; ta.style.height = 'auto';
		var r = reply; setReply(null);
		typingAt = 0; typingSay = null;
		if (photo) { var p = photo; clearPhoto(true); sendMedia({ kind: 'img', blob: p.blob, name: p.name, text: text, reply: r, preview: p.url }); }
		else post(text, r);
		buttons();
	});
	var hello = function () { var h = main.querySelector('[data-hello]'); if (h) h.remove(); };
	var done = function (tmpId, r, conv) {
		if (conv !== cur) { refreshList(); return; } // another conversation is open now: it is in the list
		var node = main.querySelector('.dm-msg[data-id="' + tmpId + '"]');
		if (!cur.t) { cur.t = r.thread; history.replaceState({ t: r.thread }, '', D.page + '?t=' + r.thread); var hd = main.querySelector('.dm-head'); if (hd) hd.outerHTML = head(cur); }
		if (node) node.remove();
		place(r.msg);
		var b = box(); if (b) { days(b); group(b); b.scrollTop = b.scrollHeight; }
		refreshList();
	};
	var failed = function (tmpId, err, conv) {
		if (conv !== cur) return;
		var node = main.querySelector('.dm-msg[data-id="' + tmpId + '"]');
		if (node) { node.classList.remove('is-pending'); node.classList.add('is-failed'); node.querySelector(':scope > time').textContent = err.message || T.failed; }
	};
	var post = function (text, r, again) {
		var id = again ? again.id : 'p' + (++pend), c = again ? again.cid : cid();
		hello();
		if (again) { again.node.remove(); }
		var tmp = { id: id, mine: true, at: Math.floor(Date.now() / 1000), html: esc(text).replace(/\n/g, '<br>'), pending: true, reply: r || null };
		drawMsgs([tmp], 'end');
		var node = main.querySelector('.dm-msg[data-id="' + id + '"]');
		if (node) { node._retry = { kind: 'text', text: text, reply: r, cid: c }; }
		var q = { text: text, cid: c };
		if (r) q.reply = r.id;
		if (cur.t) q.t = cur.t; else q.to = cur.with.login;
		var conv = cur;
		api('send', q).then(function (res) { done(id, res, conv); }).catch(function (err) { failed(id, err, conv); });
	};

	/* ---------- photos ---------- */
	var clearPhoto = function (keepUrl) {
		if (photo && !keepUrl) { try { URL.revokeObjectURL(photo.url); } catch (e) {} }
		photo = null;
		var a = main.querySelector('[data-att]');
		if (a) { a.hidden = true; a.innerHTML = ''; }
		var inp = main.querySelector('[data-photo]'); if (inp) inp.value = '';
		buttons();
	};
	/* A big photo is made smaller on the phone first (much quicker to send); the site saves it again anyway. */
	var shrink = function (file) {
		if (file.type === 'image/gif' || file.size < 1500000 || !window.createImageBitmap) return Promise.resolve(file);
		return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bm) {
			var k = Math.min(1, 2048 / Math.max(bm.width, bm.height)), c = document.createElement('canvas');
			c.width = Math.round(bm.width * k); c.height = Math.round(bm.height * k);
			c.getContext('2d').drawImage(bm, 0, 0, c.width, c.height);
			if (bm.close) bm.close();
			return new Promise(function (res) { c.toBlob(function (b) { res(b && b.size < file.size ? b : file); }, 'image/jpeg', .88); });
		}).catch(function () { return file; });
	};
	var pick = function (file) {
		if (!file || !cur || !cur.can) return;
		if (!/^image\/(png|jpe?g|webp|gif)$/.test(file.type)) { alert(T.notImg); return; }
		if (M.max && file.size > M.max) { alert(fmt(T.tooBig, Math.round(M.max / 1048576))); return; }
		stopRec();
		clearPhoto();
		var url = URL.createObjectURL(file);
		photo = { blob: file, url: url, name: file.name || 'photo.jpg' };
		shrink(file).then(function (b) { if (photo && photo.url === url) photo.blob = b; });
		var a = main.querySelector('[data-att]');
		if (a) { a.hidden = false; a.innerHTML = '<span class="dm-att-img"><img src="' + esc(url) + '" alt=""><button type="button" class="dm-att-x" data-act="photo-x" aria-label="' + esc(T.remove) + '">' + svg(I.x, 14) + '</button></span>'; }
		buttons();
		var ta = main.querySelector('[data-text]'); if (ta && !coarse) ta.focus({ preventScroll: true });
	};
	main.addEventListener('change', function (e) { if (e.target.matches('[data-photo]') && e.target.files && e.target.files[0]) pick(e.target.files[0]); });
	main.addEventListener('paste', function (e) {
		if (!e.clipboardData || !main.querySelector('[data-photo]')) return;
		var f = [].filter.call(e.clipboardData.files || [], function (x) { return /^image\//.test(x.type); })[0];
		if (f) { e.preventDefault(); pick(f); }
	});
	['dragenter', 'dragover'].forEach(function (ev) { main.addEventListener(ev, function (e) { if (main.querySelector('[data-photo]') && e.dataTransfer && [].indexOf.call(e.dataTransfer.types || [], 'Files') > -1) { e.preventDefault(); main.classList.add('is-drop'); } }); });
	['dragleave', 'drop'].forEach(function (ev) { main.addEventListener(ev, function (e) { if (ev === 'dragleave' && main.contains(e.relatedTarget)) return; main.classList.remove('is-drop'); }); });
	main.addEventListener('drop', function (e) { if (!main.querySelector('[data-photo]') || !e.dataTransfer || !e.dataTransfer.files.length) return; e.preventDefault(); pick(e.dataTransfer.files[0]); });

	/* A photo or voice message: shown at once, sent with its own ID (sent again after a failure, it is not saved twice). */
	var sendMedia = function (o, again) {
		var id = again ? again.id : 'p' + (++pend), c = again ? again.cid : cid();
		hello();
		if (again) again.node.remove();
		var local = o.kind === 'img' ? '<img src="' + esc(o.preview) + '" alt="">' : '<span class="dm-voice is-local">' + svg(I.mic, 18) + '<b>' + mmss(o.secs || 0) + '</b></span>';
		var tmp = { id: id, mine: true, at: Math.floor(Date.now() / 1000), html: o.text ? esc(o.text).replace(/\n/g, '<br>') : '', pending: true, local: local, reply: o.reply || null };
		drawMsgs([tmp], 'end');
		var node = main.querySelector('.dm-msg[data-id="' + id + '"]');
		if (node) node._retry = { kind: o.kind, o: o, cid: c };
		var fd = new FormData();
		fd.append('file', o.blob, o.kind === 'img' ? (o.blob.type === 'image/jpeg' ? 'photo.jpg' : o.name) : 'voice.' + (/ogg/.test(o.blob.type) ? 'ogg' : /mp4|aac|m4a/.test(o.blob.type) ? 'm4a' : 'webm'));
		fd.append('kind', o.kind); fd.append('cid', c);
		if (o.text) fd.append('text', o.text);
		if (o.reply) fd.append('reply', o.reply.id);
		if (o.secs) fd.append('d', Math.round(o.secs));
		if (cur.t) fd.append('t', cur.t); else fd.append('to', cur.with.login);
		var conv = cur;
		call('media', null, 'POST', fd).then(function (res) { done(id, res, conv); if (o.preview && o.kind === 'img') setTimeout(function () { try { URL.revokeObjectURL(o.preview); } catch (e) {} }, 30000); }).catch(function (err) { failed(id, err, conv); });
	};

	/* ---------- voice messages ---------- */
	var stopRec = function (keep) {
		if (!rec) return;
		var r = rec; rec = null;
		clearInterval(r.timer); clearTimeout(r.cap);
		r.keep = !!keep;
		try { if (r.mr.state !== 'inactive') r.mr.stop(); } catch (e) {}
		if (!keep) r.stream.getTracks().forEach(function (t) { t.stop(); });
		var ui = main.querySelector('[data-rec]'), f = main.querySelector('[data-form]');
		if (ui) ui.hidden = true;
		if (f) f.hidden = false;
	};
	var startRec = function () {
		if (!cur || !cur.can) return;
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) { alert(T.micNone); return; }
		var types = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm'], type = '';
		for (var i = 0; i < types.length; i++) { if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) { type = types[i]; break; } }
		navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }).then(function (stream) {
			if (!cur) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
			var mr = type ? new MediaRecorder(stream, { mimeType: type, audioBitsPerSecond: 32000 }) : new MediaRecorder(stream);
			var r = { mr: mr, stream: stream, chunks: [], at: Date.now(), reply: reply };
			rec = r;
			mr.ondataavailable = function (e) { if (e.data && e.data.size) r.chunks.push(e.data); };
			mr.onstop = function () {
				stream.getTracks().forEach(function (t) { t.stop(); });
				if (!r.keep) return;
				var secs = (Date.now() - r.at) / 1000;
				if (secs < .8 || !r.chunks.length) return;
				var blob = new Blob(r.chunks, { type: (mr.mimeType || type || 'audio/webm').split(';')[0] });
				var rp = reply; setReply(null);
				sendMedia({ kind: 'voice', blob: blob, secs: Math.min(secs, M.secs || 180), reply: rp });
			};
			mr.start(1000);
			var ui = main.querySelector('[data-rec]'), f = main.querySelector('[data-form]'), t = main.querySelector('[data-rect]');
			if (f) f.hidden = true;
			if (ui) ui.hidden = false;
			r.timer = setInterval(function () { if (t) t.textContent = mmss((Date.now() - r.at) / 1000); }, 250);
			r.cap = setTimeout(function () { if (rec === r) stopRec(true); }, (M.secs || 180) * 1000); // the longest one: sent as it is
		}).catch(function () { alert(T.micNo); });
	};

	/* ---------- playing voice messages (one at a time) ---------- */
	var player = null, playing = null;
	var play = function (v) {
		if (!player) {
			player = new Audio();
			player.preload = 'none';
			player.addEventListener('timeupdate', function () { if (!playing) return; var d = player.duration && isFinite(player.duration) ? player.duration : +playing.getAttribute('data-d') || 1; playing.querySelector('[data-vfill]').style.width = Math.min(100, player.currentTime / d * 100) + '%'; playing.querySelector('[data-vtime]').textContent = mmss(player.currentTime); });
			player.addEventListener('ended', function () { stopPlay(); });
			player.addEventListener('error', function () { stopPlay(); });
		}
		if (playing === v) { if (player.paused) { player.play().catch(function () {}); v.classList.add('is-playing'); v.querySelector('.dm-vplay').innerHTML = svg(I.pause, 20); } else { player.pause(); v.classList.remove('is-playing'); v.querySelector('.dm-vplay').innerHTML = svg(I.play, 20); } return; }
		stopPlay();
		playing = v;
		player.src = v.getAttribute('data-voice');
		player.play().catch(function () { stopPlay(); });
		v.classList.add('is-playing');
		v.querySelector('.dm-vplay').innerHTML = svg(I.pause, 20);
	};
	var stopPlay = function () {
		if (!playing) return;
		var v = playing; playing = null;
		try { player.pause(); } catch (e) {}
		if (v.isConnected) { v.classList.remove('is-playing'); v.querySelector('.dm-vplay').innerHTML = svg(I.play, 20); v.querySelector('[data-vfill]').style.width = '0'; v.querySelector('[data-vtime]').textContent = mmss(+v.getAttribute('data-d') || 0); }
	};

	/* ---------- a photo, big ---------- */
	var view = function (src) {
		var d = document.createElement('div');
		d.className = 'dm-view';
		d.setAttribute('role', 'dialog');
		d.innerHTML = '<img src="' + esc(src) + '" alt=""><button type="button" class="dm-view-x" aria-label="' + esc(T.close) + '">' + svg(I.x, 24) + '</button>';
		var close = function () { d.remove(); document.removeEventListener('keydown', key); };
		var key = function (e) { if (e.key === 'Escape') close(); };
		d.addEventListener('click', close);
		document.addEventListener('keydown', key);
		document.body.appendChild(d);
	};

	/* ---------- menus, unsend, report, block ---------- */
	var closeMenus = function () { main.querySelectorAll('.dm-menu').forEach(function (m) { m.hidden = true; }); main.querySelectorAll('.dm-msg-menu').forEach(function (m) { m.remove(); }); };
	document.addEventListener('click', function (e) { if (!e.target.closest('.dm-menu-w, .dm-msg-menu, [data-act="msg"]')) closeMenus(); });
	main.addEventListener('click', function (e) {
		if (Date.now() - lpAt < 700 && !e.target.closest('.dm-acts, .dm-msg-menu')) { e.preventDefault(); return; } // the tap that ended a long press
		var fl = e.target.closest('.dm-msg.is-failed');
		if (fl && fl._retry) {
			var rt = fl._retry, again = { id: fl.getAttribute('data-id'), cid: rt.cid, node: fl };
			if (rt.kind === 'text') post(rt.text, rt.reply, again); else sendMedia(rt.o, again);
			return;
		}
		var vw = e.target.closest('[data-view]');
		if (vw) { view(vw.getAttribute('data-view')); return; }
		var jump = e.target.closest('[data-jump]');
		if (jump) {
			var to = main.querySelector('.dm-msg[data-id="' + jump.getAttribute('data-jump') + '"]');
			if (to) { to.scrollIntoView({ behavior: 'smooth', block: 'center' }); to.classList.add('is-flash'); setTimeout(function () { to.classList.remove('is-flash'); }, 1400); }
			return;
		}
		var tap = e.target.closest('.dm-b');
		if (tap && coarse && !e.target.closest('a, button, .dm-voice')) { // phones: a tap on a message shows its ↩ and ⋮
			var mm = tap.closest('.dm-msg');
			if (mm) { var was = mm.classList.contains('is-act'); main.querySelectorAll('.dm-msg.is-act').forEach(function (x) { x.classList.remove('is-act'); }); mm.classList.toggle('is-act', !was); }
			return;
		}
		var b = e.target.closest('[data-act]');
		if (!b) return;
		var act = b.getAttribute('data-act');
		if (act === 'back') { history.pushState({}, '', D.page); none(); }
		else if (act === 'menu') { var m = b.nextElementSibling; var was2 = m.hidden; closeMenus(); m.hidden = !was2; }
		else if (act === 'play') play(b.closest('[data-voice]'));
		else if (act === 'rec') startRec();
		else if (act === 'rec-x') stopRec(false);
		else if (act === 'rec-send') stopRec(true);
		else if (act === 'photo-x') clearPhoto();
		else if (act === 'reply') { closeMenus(); setReply(replyFrom(b.closest('.dm-msg'))); }
		else if (act === 'reply-x') setReply(null);
		else if (act === 'older') {
			var firstMsg = main.querySelector('.dm-msg[data-id]');
			b.disabled = true;
			api('thread', { t: cur.t, before: firstMsg.getAttribute('data-id') }, 'GET').then(function (v) {
				var w = main.querySelector('[data-older-w]'); w.innerHTML = v.older ? '<button type="button" class="dm-older" data-act="older">' + esc(T.older) + '</button>' : '';
				drawMsgs(v.msgs, 'start');
			}).catch(function () { b.disabled = false; });
		}
		else if (act === 'block') {
			closeMenus();
			if (!cur.blocked && !confirm(fmt(T.blockQ, cur.with.name))) return;
			api('block', cur.t ? { t: cur.t, on: cur.blocked ? 0 : 1 } : { to: cur.with.login, on: 1 }).then(function () { open({ t: cur.t || '', to: cur.t ? '' : cur.with.login }); }).catch(function (er) { alert(er.message); });
		}
		else if (act === 'leave') {
			closeMenus();
			if (!confirm(T.leaveQ)) return;
			api('leave', { t: cur.t }).then(function () { inbox = inbox.filter(function (x) { return x.t !== cur.t; }); history.pushState({}, '', D.page); none(); }).catch(function (er) { alert(er.message); });
		}
		else if (act === 'msg') {
			var msg = b.closest('.dm-msg'), had = msg.querySelector('.dm-msg-menu');
			closeMenus();
			if (had) return;
			var txt = msg.querySelector('.dm-text');
			msg.insertAdjacentHTML('beforeend', '<div class="dm-msg-menu">'
				+ '<button type="button" data-act="reply">' + svg(I.reply, 16) + esc(T.reply) + '</button>'
				+ (txt && navigator.clipboard ? '<button type="button" data-act="copy">' + svg(I.copy, 16) + esc(T.copy) + '</button>' : '')
				+ (msg.classList.contains('is-mine')
					? '<button type="button" data-act="unsend">' + svg(I.undo, 16) + esc(T.unsend) + '</button>'
					: '<button type="button" data-act="report">' + svg(I.flag, 16) + esc(T.report) + '</button>') + '</div>');
		}
		else if (act === 'copy') {
			var cm = b.closest('.dm-msg'), ct = cm.querySelector('.dm-text'); closeMenus();
			if (ct) navigator.clipboard.writeText(ct.textContent).catch(function () {});
		}
		else if (act === 'unsend') {
			var um = b.closest('.dm-msg'); closeMenus();
			if (!confirm(T.unsendQ)) return;
			api('unsend', { id: +um.getAttribute('data-id') }).then(function () { gone([+um.getAttribute('data-id')]); refreshList(); }).catch(function (er) { alert(er.message); });
		}
		else if (act === 'report') { var rm = b.closest('.dm-msg'); closeMenus(); report(+rm.getAttribute('data-id')); }
	});
	var gone = function (ids) {
		ids.forEach(function (id) {
			var n = main.querySelector('.dm-msg[data-id="' + id + '"]');
			if (n && !n.classList.contains('is-gone')) {
				if (playing && n.contains(playing)) stopPlay();
				n.outerHTML = bubble({ id: id, mine: n.classList.contains('is-mine'), at: +n.getAttribute('data-at'), gone: 1 });
			}
		});
		var b = box(); if (b) group(b);
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
				api('report', { id: id, why: f.querySelector('[name=why]:checked').value, block: blk ? 1 : 0 }).then(function () { alert(T.reported); if (blk) open({ t: cur.t }); }).catch(function (er) { alert(er.message); });
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
	var offline = function (on) {
		var n = main.querySelector('[data-offline]');
		if (on && !n && cur) { var h = main.querySelector('.dm-head'); if (h) h.insertAdjacentHTML('afterend', '<div class="dm-offline" data-offline>' + esc(T.offline) + '</div>'); }
		if (!on && n) n.remove();
	};
	var poll = function () {
		if (!cur || !cur.t || busy) return Promise.resolve();
		busy = true;
		var t = cur.t, q = { t: t, after: lastId };
		if (typingSay !== null) { q.typing = typingSay; typingSay = null; }
		return api('thread', q, 'GET').then(function (v) {
			fails = 0; offline(false);
			if (!cur || v.t !== cur.t) return;
			var fresh = v.msgs.filter(function (m) { return !main.querySelector('.dm-msg[data-id="' + m.id + '"]'); });
			if (fresh.length) { hello(); drawMsgs(fresh, 'end'); }
			if (v.msgs.length) lastId = Math.max(lastId, v.msgs[v.msgs.length - 1].id);
			if (v.gone && v.gone.length) gone(v.gone);
			var changed = cur.seen !== v.seen;
			cur.seen = v.seen; cur.with = v.with; cur.media = v.media;
			if (cur.can !== v.can) { cur.can = v.can; cur.why = v.why; cur.blocked = v.blocked; stopRec(); var c = main.querySelector('[data-compose], .dm-cant'); if (c) c.outerHTML = composer(cur); }
			setTyping(!!v.typing && !fresh.some(function (m) { return !m.mine; }));
			if (changed) seen();
			if (fresh.some(function (m) { return !m.mine; })) refreshList();
		}).catch(function (e) { fails++; if (!e.status || e.status >= 500) offline(fails > 1); }).then(function () { busy = false; });
	};
	var schedule = function () {
		clearTimeout(pollT); clearTimeout(inboxT);
		var every = function () { // faster while they type; slower in a hidden tab and while the site cannot be reached
			if (document.hidden) return 20000;
			var base = cur && cur.typing ? 1500 : 3000;
			return fails ? Math.min(30000, base * Math.pow(2, fails)) : base;
		};
		var tick = function () { poll().then(function () { pollT = setTimeout(tick, every()); }); };
		var tickList = function () { refreshList().then(function () { inboxT = setTimeout(tickList, document.hidden ? 60000 : 15000); }); };
		pollT = setTimeout(tick, every());
		inboxT = setTimeout(tickList, document.hidden ? 60000 : 15000);
	};
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); refreshList(); } schedule(); });
	window.addEventListener('online', function () { fails = 0; poll(); refreshList(); schedule(); });
	window.addEventListener('pagehide', function () { stopRec(false); stopPlay(); });
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
