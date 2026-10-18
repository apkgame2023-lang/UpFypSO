/*
 * Y4APK ▸ App updates: upload a new file from the browser straight to
 * Cloudflare R2. inc/r2.php starts a multipart upload and signs a link for each
 * part; the parts go up four at a time (each retried up to three times), then
 * the server finishes the upload and checks R2 has every byte. The row's hidden
 * field gets the file's key; Save points the download page at it.
 */
(function () {
	'use strict';
	var C = window.y4apkR2;
	if (!C) {
		return;
	}
	var T = C.text, running = 0;

	function fmt(s) {
		var args = [].slice.call(arguments, 1);
		return s.replace(/%(\d)\$s/g, function (_, n) { return args[n - 1]; }).replace('%s', args[0]).replace(/%%/g, '%');
	}
	function bytes(b) {
		return b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : (b / 1048576).toFixed(1) + ' MB';
	}
	function ajax(action, data) {
		var body = new FormData();
		body.append('action', 'y4apk_r2_' + action);
		body.append('nonce', C.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (v && typeof v === 'object') {
				Object.keys(v).forEach(function (i) { body.append(k + '[' + i + ']', v[i]); });
			} else {
				body.append(k, v);
			}
		});
		return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j || !j.success) {
					throw new Error((j && j.data && j.data.message) || 'error');
				}
				return j.data;
			});
	}
	function wait(ms) {
		return new Promise(function (ok) { setTimeout(ok, ms); });
	}

	function Upload(box, file) {
		this.box = box;
		this.file = file;
		this.line = box.closest('.au-line');
		this.form = box.closest('form');
		this.status = this.line.querySelector('.au-up-status');
		this.keyField = box.querySelector('.au-up-key');
		this.xhrs = [];
		this.loaded = {};
		this.stopped = false;
	}

	Upload.prototype.show = function (text, button, onclick) {
		this.status.hidden = false;
		this.status.textContent = text + ' ';
		if (button) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'button-link';
			b.textContent = button;
			b.addEventListener('click', onclick);
			this.status.appendChild(b);
		}
	};

	Upload.prototype.busy = function (on) {
		running += on ? 1 : -1;
		this.box.classList.toggle('is-busy', on);
		this.form.querySelector('.au-foot .button-primary').disabled = on;
	};

	Upload.prototype.url = function (n) {
		var u = this;
		if (u.urls[n]) {
			return Promise.resolve(u.urls[n]);
		}
		var numbers = [];
		for (var i = n; i < n + 50 && i <= u.count; i++) {
			numbers.push(i);
		}
		return ajax('parts', { uploadId: u.id, numbers: numbers }).then(function (d) {
			Object.keys(d.urls).forEach(function (k) { u.urls[k] = d.urls[k]; });
			return u.urls[n];
		});
	};

	Upload.prototype.part = function (n, tries) {
		var u = this, size = u.partSize;
		return u.url(n).then(function (url) {
			return new Promise(function (ok, no) {
				var x = new XMLHttpRequest();
				u.xhrs.push(x);
				x.open('PUT', url);
				x.upload.onprogress = function (e) {
					u.loaded[n] = e.loaded;
					u.progress();
				};
				x.onload = function () {
					var etag = x.getResponseHeader('ETag');
					if (x.status >= 200 && x.status < 300 && etag) {
						u.loaded[n] = Math.min(size, u.file.size - (n - 1) * size);
						ok(etag);
					} else {
						no(new Error(x.status >= 200 && x.status < 300 ? 'cors' : 'HTTP ' + x.status));
					}
				};
				x.onerror = function () { no(new Error('cors')); };
				x.onabort = function () { no(new Error('cancelled')); };
				x.send(u.file.slice((n - 1) * size, Math.min(n * size, u.file.size)));
			});
		}).catch(function (e) {
			if (u.stopped || e.message === 'cancelled' || tries >= 3) {
				throw e;
			}
			u.loaded[n] = 0;
			return wait(1000 * Math.pow(2, tries)).then(function () { return u.part(n, tries + 1); });
		});
	};

	Upload.prototype.progress = function () {
		var u = this, now = Date.now();
		if (now - (u.painted || 0) < 250) {
			return;
		}
		u.painted = now;
		var sent = Object.keys(u.loaded).reduce(function (a, k) { return a + u.loaded[k]; }, 0);
		var speed = sent / Math.max(1, (now - u.started) / 1000);
		u.show(fmt(T.progress, Math.floor(100 * sent / u.file.size), bytes(sent), bytes(u.file.size), bytes(speed)), T.cancel, function () { u.cancel(); });
	};

	Upload.prototype.run = function () {
		var u = this, line = u.line;
		var version = line.querySelector('input[name="version"], input[name$="[version]"]');
		u.busy(true);
		u.started = Date.now();
		u.show(T.start, T.cancel, function () { u.cancel(); });
		ajax('start', {
			post: u.form.querySelector('input[name=post]').value,
			name: u.file.name,
			size: u.file.size,
			type: (line.querySelector('.au-type').textContent || 'MOD').trim(),
			version: version ? version.value : ''
		}).then(function (s) {
			u.id = s.uploadId;
			u.key = s.key;
			u.partSize = s.partSize;
			u.count = s.count;
			u.urls = s.urls || {};
			u.etags = {};
			var next = 1;
			function worker() {
				if (u.stopped) {
					return Promise.reject(new Error('cancelled'));
				}
				if (next > u.count) {
					return Promise.resolve();
				}
				var n = next++;
				return u.part(n, 0).then(function (etag) {
					u.etags[n] = etag;
					return worker();
				});
			}
			var pool = [];
			for (var i = 0; i < Math.min(4, u.count); i++) {
				pool.push(worker());
			}
			return Promise.all(pool);
		}).then(function () {
			u.show(T.finishing);
			return ajax('complete', { uploadId: u.id, etags: u.etags });
		}).then(function (d) {
			u.keyField.value = d.key;
			var size = line.querySelector('input[name="size"], input[name$="[size]"]');
			if (size) {
				size.value = d.size;
			}
			u.show(fmt(T.done, u.file.name + ' (' + d.size + ')'));
			u.busy(false);
		}).catch(function (e) {
			u.busy(false);
			if (u.stopped) {
				return;
			}
			u.stop();
			u.show(e.message === 'cors' ? T.cors : fmt(T.failed, e.message), T.retry, function () {
				new Upload(u.box, u.file).run();
			});
		});
	};

	Upload.prototype.stop = function () {
		this.stopped = true;
		this.xhrs.forEach(function (x) { x.abort(); });
		if (this.id) {
			ajax('abort', { uploadId: this.id }).catch(function () {});
		}
	};

	Upload.prototype.cancel = function () {
		this.stop();
		this.keyField.value = '';
		this.show(T.cancelled);
	};

	document.addEventListener('change', function (e) {
		var input = e.target;
		if (input.type !== 'file' || !input.closest('.au-up') || !input.files.length) {
			return;
		}
		var box = input.closest('.au-up');
		box.querySelector('.au-up-key').value = '';
		new Upload(box, input.files[0]).run();
		input.value = '';
	});

	window.addEventListener('beforeunload', function (e) {
		if (running > 0) {
			e.preventDefault();
			e.returnValue = T.leave;
			return T.leave;
		}
	});

	// Settings ▸ Test the connection: the keys (server side), then a small upload from this browser (CORS).
	var test = document.getElementById('au-r2-test');
	if (test) {
		test.addEventListener('click', function () {
			var out = document.getElementById('au-r2-test-result');
			out.textContent = '…';
			ajax('test').then(function (d) {
				var folders = d.folders >= 0 ? ' · ✓ ' + d.folders + ' download folders' + (d.writable ? '' : ' (✗ not writable)') : ' · ✗ download folder not found';
				return fetch(d.put, { method: 'PUT', body: 'ok' }).then(function (r) {
					var good = r.ok && r.headers.get('ETag');
					out.textContent = '✓ Keys work · ' + (good ? '✓ This browser can upload (CORS)' : '✗ ' + T.cors) + folders;
				}).catch(function () {
					out.textContent = '✓ Keys work · ✗ ' + T.cors + folders;
				}).then(function () { return ajax('test_clean'); });
			}).catch(function (e) {
				out.textContent = '✗ ' + e.message;
			});
		});
	}
})();
