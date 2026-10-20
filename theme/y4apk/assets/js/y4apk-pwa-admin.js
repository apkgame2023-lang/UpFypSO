/*
 * Y4APK ▸ App admin page: Media Library pickers and the "Check the app" panel.
 * Strings arrive as window.Y4APK_PWA_ADMIN (see Y4APK_PWA::admin_assets()).
 */
jQuery(function ($) {
	'use strict';

	var T = window.Y4APK_PWA_ADMIN || {};

	/* ---- custom icon ---------------------------------------------------- */

	var iconFrame;
	$('#pwa-pick-icon').on('click', function (e) {
		e.preventDefault();
		if (!iconFrame) {
			iconFrame = wp.media({ title: T.iconTitle, button: { text: T.useImage }, library: { type: 'image' }, multiple: false });
			iconFrame.on('select', function () {
				var img = iconFrame.state().get('selection').first().toJSON();
				var thumb = (img.sizes && img.sizes.thumbnail) ? img.sizes.thumbnail.url : img.url;
				$('#pwa-icon-custom').val(img.id);
				$('#pwa-custom-preview').attr('src', thumb).show();
				$('#pwa-src-custom').prop('checked', true);
			});
		}
		iconFrame.open();
	});
	$('#pwa-icon-file').on('change', function () {
		if (this.files.length) { $('#pwa-src-custom').prop('checked', true); }
	});

	/* ---- screenshots ---------------------------------------------------- */

	var shotsFrame;
	function showShots(images) {
		var box = $('#pwa-shots').empty();
		images.forEach(function (img) {
			var src = (img.sizes && img.sizes.medium) ? img.sizes.medium.url : img.url;
			box.append($('<img alt="">').attr('src', src)
				.css({ height: '120px', width: 'auto', border: '1px solid #dcdcde', borderRadius: '6px' }));
		});
		$('#pwa-screenshots').val(images.map(function (img) { return img.id; }).join(','));
		$('#pwa-clear-shots').prop('hidden', images.length === 0);
	}
	$('#pwa-pick-shots').on('click', function (e) {
		e.preventDefault();
		if (!shotsFrame) {
			shotsFrame = wp.media({ title: T.shotsTitle, button: { text: T.useImages }, library: { type: 'image' }, multiple: 'add' });
			shotsFrame.on('open', function () {
				var selection = shotsFrame.state().get('selection');
				($('#pwa-screenshots').val() || '').split(',').filter(Boolean).forEach(function (id) {
					var att = wp.media.attachment(id); att.fetch(); selection.add(att);
				});
			});
			shotsFrame.on('select', function () {
				showShots(shotsFrame.state().get('selection').toJSON().slice(0, 8));
			});
		}
		shotsFrame.open();
	});
	$('#pwa-clear-shots').on('click', function (e) { e.preventDefault(); showShots([]); });

	/* ---- check the app ---------------------------------------------------- */

	var panel = document.getElementById('pwa-check');
	if (!panel) return;
	var list = document.getElementById('pwa-check-results');
	var button = document.getElementById('pwa-check-run');
	var d = panel.dataset;
	var JS_TYPE = /^(text\/javascript|application\/javascript|text\/ecmascript|module)$/i;

	function row(state, title, detail) {
		var icon = { ok: '✓', warn: '!', bad: '✗', run: '…' }[state];
		var colour = { ok: '#008a20', warn: '#996800', bad: '#b32d2e', run: '#50575e' }[state];
		var li = document.createElement('li');
		li.style.cssText = 'display:flex;gap:.6rem;margin:.6rem 0;align-items:flex-start';
		li.innerHTML = '<span style="flex:none;width:1.4rem;height:1.4rem;border-radius:50%;color:#fff;text-align:center;' +
			'line-height:1.4rem;font-weight:700;background:' + colour + '">' + icon + '</span><span><strong></strong><br><span></span></span>';
		li.querySelector('strong').textContent = title;
		li.querySelector('span span').textContent = detail || '';
		li.dataset.state = state;
		list.appendChild(li);
		return li;
	}

	function get(url) {
		// credentials: omit → served exactly as to a visitor who is not logged in,
		// which is the version caches and speed plugins rewrite.
		return fetch(url, { credentials: 'omit', cache: 'no-store', redirect: 'follow' });
	}

	async function checkManifest() {
		try {
			var r = await get(d.manifest);
			var m = await r.json();
			if (!r.ok || !m.icons || !m.icons.length) throw new Error('HTTP ' + r.status);
			row('ok', T.manifest, T.manifestOk.replace('%d', m.icons.length));
		} catch (e) {
			row('bad', T.manifest, T.manifestBad);
		}
	}

	async function checkWorkerScript() {
		try {
			var r = await get(d.sw);
			var type = r.headers.get('content-type') || '';
			var body = await r.text();
			if (r.ok && /javascript/i.test(type) && body.indexOf('y4apk') !== -1) {
				row('ok', T.worker, T.workerOk);
				return true;
			}
			row('bad', T.worker, /html/i.test(type) ? T.workerHtml : T.workerBad.replace('%s', r.status + ' ' + type));
		} catch (e) {
			row('bad', T.worker, T.workerBad.replace('%s', e.message));
		}
		return false;
	}

	async function checkPage() {
		try {
			var r = await get(d.home);
			var doc = new DOMParser().parseFromString(await r.text(), 'text/html');
			var boot = doc.getElementById('y4apk-pwa-boot');
			if (!doc.querySelector('link[rel="manifest"]')) {
				row('bad', T.page, T.pageNoManifest);
			} else if (!boot) {
				row('bad', T.page, T.pageNoBoot);
			} else if (boot.getAttribute('type') && !JS_TYPE.test(boot.getAttribute('type'))) {
				row('bad', T.page, T.pageDelayed.replace('%s', boot.getAttribute('type')));
			} else {
				row('ok', T.page, T.pageOk);
			}
		} catch (e) {
			row('warn', T.page, T.pageUnknown);
		}
	}

	async function checkRegistration() {
		if (!('serviceWorker' in navigator)) {
			row('warn', T.browser, T.browserNoSupport);
			return;
		}
		var pending = row('run', T.browser, T.browserWaiting);
		try {
			var reg = await navigator.serviceWorker.register(d.sw, { scope: d.scope });
			var started = await new Promise(function (resolve) {
				var deadline = Date.now() + 15000;
				(function poll() {
					if (reg.active) return resolve(true);
					if (Date.now() > deadline) return resolve(false);
					setTimeout(poll, 250);
				})();
			});
			pending.remove();
			row(started ? 'ok' : 'warn', T.browser, started ? T.browserOk : T.browserSlow);
		} catch (e) {
			pending.remove();
			row('bad', T.browser, T.browserBad.replace('%s', e.message));
		}
	}

	button.addEventListener('click', async function () {
		button.disabled = true;
		list.innerHTML = '';
		await checkManifest();
		var scriptOk = await checkWorkerScript();
		await checkPage();
		if (scriptOk) await checkRegistration();
		var bad = list.querySelectorAll('[data-state="bad"]').length;
		row(bad ? 'warn' : 'ok', bad ? T.summaryBad : T.summaryOk, bad ? T.summaryBadHint : T.summaryOkHint);
		button.disabled = false;
	});
});
