/*
 * Y4APK service worker.
 *
 * Served by inc/pwa.php at /?y4apk_pwa=sw so its scope covers the whole site;
 * the __PLACEHOLDERS__ are filled in there.
 *
 * Deliberately conservative — a broken service worker keeps breaking a site for
 * returning visitors until it is replaced, so this one only ever:
 *   - pages:  network first, falling back to a recent copy, then the offline page
 *   - assets: same-origin CSS/JS/fonts/images, served from cache and refreshed
 *             in the background
 * and leaves alone everything it has no business touching: anything that is not
 * a GET, every other origin (ads, analytics, CDNs), wp-admin, logins, the REST
 * API, AJAX, the download pages and the files themselves.
 */

const VERSION = '__VERSION__';
const SCOPE = '__SCOPE__';
const OFFLINE_URL = '__OFFLINE_URL__';
const PRECACHE = __PRECACHE__;

const PREFIX = 'y4apk-';
const STATIC_CACHE = PREFIX + 'static-' + VERSION;
const PAGE_CACHE = PREFIX + 'pages-' + VERSION;
const MAX_PAGES = 30;
const MAX_STATIC = 150;

const BYPASS_PATHS = [
	'wp-admin/', 'wp-login.php', 'wp-json/', 'wp-cron.php', 'xmlrpc.php',
	'feed/', 'download/', '.well-known/',
];
const BYPASS_QUERY = ['y4apk_pwa', 'preview', 's', 'add-to-cart', 'customize_changeset_uuid', 'nocache'];
const FILE_DOWNLOAD = /\.(apk|apks|xapk|obb|zip|rar|7z|ipa|exe)$/i;
const STATIC_ASSET = /\.(css|js|woff2?|ttf|otf|png|jpe?g|gif|webp|avif|svg|ico)$/i;

self.addEventListener('install', (event) => {
	// One at a time rather than cache.addAll(): addAll is all-or-nothing, and a
	// single missing asset must not leave the offline page uncached.
	event.waitUntil(
		caches.open(STATIC_CACHE)
			.then((cache) => Promise.all(PRECACHE.map((url) =>
				cache.add(new Request(url, { cache: 'reload' })).catch(() => {}))))
			.then(() => self.skipWaiting())
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil((async () => {
		const keep = [STATIC_CACHE, PAGE_CACHE];
		for (const key of await caches.keys()) {
			if (key.startsWith(PREFIX) && !keep.includes(key)) {
				await caches.delete(key);
			}
		}
		if (self.registration.navigationPreload) {
			await self.registration.navigationPreload.enable();
		}
		await self.clients.claim();
	})());
});

function shouldBypass(url) {
	const path = url.pathname.startsWith(SCOPE) ? url.pathname.slice(SCOPE.length) : url.pathname;
	if (BYPASS_PATHS.some((p) => path.startsWith(p) || path.includes('/' + p))) return true;
	if (BYPASS_QUERY.some((q) => url.searchParams.has(q))) return true;
	if (url.pathname.endsWith('/admin-ajax.php')) return true;
	return FILE_DOWNLOAD.test(url.pathname);
}

async function trim(cacheName, max) {
	const cache = await caches.open(cacheName);
	const keys = await cache.keys();
	for (let i = 0; i < keys.length - max; i++) {
		await cache.delete(keys[i]);
	}
}

function cacheable(response) {
	return response && response.ok && response.type === 'basic';
}

async function handlePage(event) {
	try {
		const preloaded = await event.preloadResponse;
		const response = preloaded || await fetch(event.request);
		if (cacheable(response)) {
			const copy = response.clone();
			event.waitUntil(caches.open(PAGE_CACHE)
				.then((cache) => cache.put(event.request, copy))
				.then(() => trim(PAGE_CACHE, MAX_PAGES)));
		}
		return response;
	} catch (err) {
		const cached = await caches.match(event.request);
		return cached || (await caches.match(OFFLINE_URL)) || Response.error();
	}
}

async function handleAsset(event) {
	const cache = await caches.open(STATIC_CACHE);
	const cached = await cache.match(event.request);
	const network = fetch(event.request).then((response) => {
		if (cacheable(response)) {
			cache.put(event.request, response.clone()).then(() => trim(STATIC_CACHE, MAX_STATIC));
		}
		return response;
	}).catch(() => cached);
	if (cached) {
		event.waitUntil(network);
		return cached;
	}
	return network;
}

self.addEventListener('fetch', (event) => {
	const request = event.request;
	if (request.method !== 'GET') return;

	const url = new URL(request.url);
	if (url.origin !== self.location.origin) return;
	if (!url.pathname.startsWith(SCOPE)) return;
	if (shouldBypass(url)) return;

	if (request.mode === 'navigate') {
		event.respondWith(handlePage(event));
		return;
	}

	if (['style', 'script', 'font', 'image'].includes(request.destination) || STATIC_ASSET.test(url.pathname)) {
		event.respondWith(handleAsset(event));
	}
});
