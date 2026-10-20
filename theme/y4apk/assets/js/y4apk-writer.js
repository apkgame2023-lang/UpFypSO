/*
 * Y4APK ▸ Write with ChatGPT: the page. "Write selected now" writes the ticked
 * posts one after another (each request is one post, up to a minute), showing
 * each result on its row; the queue buttons hand posts to the background
 * instead. Settings has Test and "Try it on one post".
 */
(function () {
	'use strict';
	var C = window.y4apkWriter;
	if (!C) {
		return;
	}
	var T = C.text, running = false, stopped = false;

	function fmt(s) {
		var args = [].slice.call(arguments, 1);
		return s.replace(/%(\d)\$s/g, function (_, n) { return args[n - 1]; }).replace('%s', args[0]);
	}
	function $(sel, root) {
		return (root || document).querySelector(sel);
	}
	function $$(sel, root) {
		return [].slice.call((root || document).querySelectorAll(sel));
	}
	function ajax(action, data) {
		var body = new FormData();
		body.append('action', 'y4apk_writer_' + action);
		body.append('nonce', C.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) {
				v.forEach(function (x) { body.append(k + '[]', x); });
			} else {
				body.append(k, v);
			}
		});
		return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: body })
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
	function wait(ms) {
		return new Promise(function (ok) { setTimeout(ok, ms); });
	}
	function parts() {
		return $$('.aw-part:checked').reduce(function (a, c) { return a | parseInt(c.value, 10); }, 0);
	}
	function picked() {
		return $$('.aw-pick:checked').map(function (c) { return c.value; });
	}

	/* ---- Posts ---- */

	var bar = $('#aw-progress');
	function progress(done, total, text) {
		bar.hidden = false;
		$('.au-bar > span', bar).style.width = (total ? Math.round(100 * done / total) : 0) + '%';
		$('.aw-progress-text', bar).textContent = text;
	}

	function writeRow(row, tries) {
		var msg = $('.aw-msg', row);
		row.classList.remove('is-good', 'is-bad');
		row.classList.add('is-busy');
		msg.className = 'aw-msg';
		msg.textContent = T.working;
		return ajax('write', { id: row.dataset.id, parts: parts() }).then(function (r) {
			// Busy for a moment (Google Play, or the AI's per-minute limit): try this post again shortly.
			var pause = r.code === 'busy' ? Math.max(15, r.wait || 15) : 15;
			if (!r.ok && r.retry && r.code !== 'locked' && pause <= 90 && (tries || 0) < 2 && !stopped) {
				msg.textContent = r.message;
				return wait(pause * 1000).then(function () { return writeRow(row, (tries || 0) + 1); });
			}
			row.classList.remove('is-busy');
			row.classList.add(r.ok ? 'is-good' : 'is-bad');
			$('.aw-state', row).innerHTML = r.status;
			msg.className = 'aw-msg' + (r.ok ? '' : ' is-bad');
			msg.textContent = r.message;
			$('.aw-undo', row).hidden = !r.ok;
			$('.aw-pick', row).checked = false;
			return r;
		}).catch(function (e) {
			row.classList.remove('is-busy');
			row.classList.add('is-bad');
			msg.className = 'aw-msg is-bad';
			msg.textContent = e.message;
			return { ok: false, code: 'request', message: e.message };
		});
	}

	function writeAll(rows) {
		if (!parts()) {
			return alert(T.parts);
		}
		if (!rows.length) {
			return alert(T.pick);
		}
		running = true;
		stopped = false;
		var good = 0, bad = 0, i = 0;
		$$('#aw-write, #aw-queue, #aw-queue-all, .aw-one').forEach(function (b) { b.disabled = true; });
		function next() {
			if (stopped || i >= rows.length) {
				return Promise.resolve();
			}
			var row = rows[i];
			progress(i, rows.length, fmt(T.writing, i + 1, rows.length, row.dataset.title));
			row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			return writeRow(row).then(function (r) {
				i++;
				r.ok ? good++ : bad++;
				// The account, not the post (key, credit, model, a daily limit): no use going on.
				if (!r.ok && (['key', 'credit', 'model'].indexOf(r.code) >= 0 || (r.code === 'busy' && r.wait > 90))) {
					stopped = true;
				}
				return next();
			});
		}
		return next().then(function () {
			running = false;
			progress(i, rows.length, stopped && i < rows.length ? fmt(T.stopped, i) + ' ' + fmt(T.done, good, bad) : fmt(T.done, good, bad));
			$$('#aw-write, #aw-queue, #aw-queue-all, .aw-one').forEach(function (b) { b.disabled = false; });
		});
	}

	var all = $('#aw-all');
	if (all) {
		all.addEventListener('change', function () {
			$$('.aw-pick').forEach(function (c) { c.checked = all.checked; });
		});
	}
	var write = $('#aw-write');
	if (write) {
		write.addEventListener('click', function () {
			writeAll($$('.aw-pick:checked').map(function (c) { return c.closest('.aw-row'); }));
		});
	}
	var stop = $('#aw-stop');
	if (stop) {
		stop.addEventListener('click', function () { stopped = true; });
	}
	document.addEventListener('click', function (e) {
		var one = e.target.closest('.aw-one');
		if (one && !running) {
			writeAll([one.closest('.aw-row')]);
		}
		var undo = e.target.closest('.aw-undo');
		if (undo && !running && confirm(T.undoAsk)) {
			var row = undo.closest('.aw-row'), msg = $('.aw-msg', row);
			msg.textContent = T.working;
			ajax('undo', { id: row.dataset.id }).then(function (r) {
				$('.aw-state', row).innerHTML = r.status;
				msg.className = 'aw-msg' + (r.ok ? '' : ' is-bad');
				msg.textContent = r.message;
				row.classList.remove('is-good', 'is-bad');
				undo.hidden = true;
			}).catch(function (err) { msg.textContent = err.message; });
		}
	});

	function queued(p) {
		return p.then(function (r) {
			progress(1, 1, r.message);
			setTimeout(function () { location.reload(); }, 900);
		}).catch(function (e) { alert(e.message); });
	}
	var queue = $('#aw-queue');
	if (queue) {
		queue.addEventListener('click', function () {
			var ids = picked();
			if (!parts()) {
				return alert(T.parts);
			}
			if (!ids.length) {
				return alert(T.pick);
			}
			queued(ajax('enqueue', { ids: ids, parts: parts() }));
		});
	}
	var queueAll = $('#aw-queue-all');
	if (queueAll) {
		queueAll.addEventListener('click', function () {
			if (!parts()) {
				return alert(T.parts);
			}
			if (confirm(fmt(T.all, $('#aw-bar').dataset.total))) {
				queued(ajax('enqueue', { all: 1, show: queueAll.dataset.show, cat: queueAll.dataset.cat, s: queueAll.dataset.s, parts: parts() }));
			}
		});
	}

	window.addEventListener('beforeunload', function (e) {
		if (running) {
			e.preventDefault();
			e.returnValue = T.leave;
			return T.leave;
		}
	});

	/* ---- Queue ---- */

	$$('.aw-q').forEach(function (b) {
		b.addEventListener('click', function () {
			if (b.dataset.do === 'clear' && !confirm(T.clear)) {
				return;
			}
			b.disabled = true;
			b.textContent = T.working;
			ajax('queue', { do: b.dataset.do }).then(function () { location.reload(); }).catch(function (e) { alert(e.message); });
		});
	});
	var speed = $('#aw-speed');
	if (speed) {
		speed.addEventListener('change', function () {
			ajax('queue', { do: 'speed', per_run: speed.value }).then(function () { location.reload(); });
		});
	}

	/* ---- Settings ---- */

	// Which AI: show its panel.
	$$('.aw-provider input[name=provider]').forEach(function (radio) {
		radio.addEventListener('change', function () {
			$$('.aw-provider').forEach(function (l) { l.classList.toggle('is-on', l.contains(radio)); });
			$$('.aw-panel').forEach(function (p) { p.hidden = p.dataset.panel !== radio.value; });
		});
	});
	$$('.aw-test').forEach(function (test) {
		test.addEventListener('click', function () {
			var provider = test.dataset.provider, out = test.parentNode.querySelector('.aw-test-result');
			out.textContent = T.working;
			ajax('test', { provider: provider }).then(function (d) {
				out.textContent = fmt(T.testOk, d.model, d.reply);
				// The account's models, keeping the ones listed already (and their names).
				var select = $('#aw-model-' + provider);
				d.models.forEach(function (m) {
					if (!$$('option', select).some(function (o) { return o.value === m; })) {
						var o = document.createElement('option');
						o.value = o.textContent = m;
						select.appendChild(o);
					}
				});
			}).catch(function (e) {
				out.textContent = '✗ ' + e.message;
			});
		});
	});

	var tryIt = $('#aw-try'), last = null;
	if (tryIt) {
		tryIt.addEventListener('click', function () {
			var out = $('#aw-try-out');
			tryIt.disabled = true;
			tryIt.textContent = T.working;
			$('#aw-apply-result').textContent = '';
			ajax('preview', { ref: $('#aw-try-ref').value }).then(function (d) {
				last = d;
				out.hidden = false;
				$('.aw-try-prompt', out).textContent = d.prompt;
				$('.aw-try-meta', out).textContent = '· ' + d.title + ' · ' + d.words + ' words · ' + d.model + (d.mod ? ' · MOD “' + d.mod + '”' + (d.found ? ' (found)' : '') : '');
				$('.aw-article', out).innerHTML = d.html;
				$('#aw-apply').disabled = false;
			}).catch(function (e) {
				out.hidden = true;
				alert(e.message);
			}).then(function () {
				tryIt.disabled = false;
				tryIt.textContent = tryIt.dataset.label || tryIt.textContent;
			});
		});
		tryIt.dataset.label = tryIt.textContent;
	}
	var apply = $('#aw-apply');
	if (apply) {
		apply.addEventListener('click', function () {
			if (!last) {
				return;
			}
			apply.disabled = true;
			ajax('apply', { id: last.id, answer: last.answer, mod: last.found ? last.mod : '' }).then(function (r) {
				$('#aw-apply-result').innerHTML = '';
				var a = document.createElement('a');
				a.href = r.view;
				a.target = '_blank';
				a.textContent = T.saved + ' (' + r.message + ')';
				$('#aw-apply-result').appendChild(a);
			}).catch(function (e) {
				apply.disabled = false;
				$('#aw-apply-result').textContent = '✗ ' + e.message;
			});
		});
	}
})();
