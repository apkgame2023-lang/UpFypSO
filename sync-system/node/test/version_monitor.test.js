'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { loadConfig, endpoint, runOnce, summarize } = require('../version_monitor');

/** A stand-in WordPress: answers /wp-json/y4apk/v1/sync/run and /wp-cron.php. */
async function startWordPress(answer) {
  const calls = [];
  const server = http.createServer((req, res) => {
    calls.push({ method: req.method, url: req.url, key: req.headers['x-y4apk-sync-key'], auth: req.headers.authorization });
    const [status, body, headers] = answer(req, calls.length);
    res.writeHead(status, { 'content-type': 'application/json', ...headers });
    res.end(typeof body === 'string' ? body : JSON.stringify(body));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const site = `http://127.0.0.1:${server.address().port}`;
  return { site, calls, close: () => new Promise((resolve) => server.close(resolve)) };
}

const RUN = {
  items: { matched: 2, nopost: 1, versions: 1 },
  posts: { added: 1, searched: 1, had: 0, unknown: 0, missing: 0 },
  push: { sent: 0, caught_up: { 4: '3.5.0' }, waiting: 0 },
  writer: { queue: 1, ai: 'gemini', waiting: '', paused: '' },
};

test('one run: POST /sync/run with the sync key, then wp-cron.php', async (t) => {
  const wp = await startWordPress((req) => (req.url.startsWith('/wp-cron.php') ? [200, ''] : [200, RUN]));
  t.after(wp.close);
  const config = loadConfig({ WP_URL: `${wp.site}/`, WP_SYNC_KEY: 'secret-key' });
  const result = await runOnce(config);
  assert.equal(result.ok, true);
  assert.deepEqual(result.run, RUN);
  assert.equal(result.cron, 200);
  assert.equal(wp.calls[0].method, 'POST');
  assert.equal(wp.calls[0].url, '/wp-json/y4apk/v1/sync/run');
  assert.equal(wp.calls[0].key, 'secret-key');
  assert.equal(wp.calls[1].url, '/wp-cron.php?doing_wp_cron');
});

test('an Application Password goes as Basic auth', async (t) => {
  const wp = await startWordPress(() => [200, RUN]);
  t.after(wp.close);
  const config = loadConfig({ WP_URL: wp.site, WP_USER: 'admin', WP_APP_PASSWORD: 'abcd efgh', MONITOR_WP_CRON: '0' });
  await runOnce(config);
  assert.equal(wp.calls.length, 1);
  assert.equal(wp.calls[0].auth, `Basic ${Buffer.from('admin:abcd efgh').toString('base64')}`);
});

test('a busy WordPress (503) is asked again', async (t) => {
  const wp = await startWordPress((req, n) => (n === 1 ? [503, { code: 'busy' }] : [200, RUN]));
  t.after(wp.close);
  const result = await runOnce(loadConfig({ WP_URL: wp.site, WP_SYNC_KEY: 'k', MONITOR_WP_CRON: '0' }));
  assert.equal(result.ok, true);
  assert.equal(wp.calls.length, 2);
});

test('a refused key fails the run with a clear reason', async (t) => {
  const wp = await startWordPress(() => [401, { code: 'y4apk_sync_auth', message: 'Send the sync key' }]);
  t.after(wp.close);
  const result = await runOnce(loadConfig({ WP_URL: wp.site, WP_SYNC_KEY: 'wrong', MONITOR_WP_CRON: '0' }));
  assert.equal(result.ok, false);
  assert.match(result.error, /refused the credentials/);
});

test('a site without the route says to update the theme', async (t) => {
  const wp = await startWordPress(() => [404, { code: 'rest_no_route' }]);
  t.after(wp.close);
  const result = await runOnce(loadConfig({ WP_URL: wp.site, WP_SYNC_KEY: 'k', MONITOR_WP_CRON: '0' }));
  assert.equal(result.ok, false);
  assert.match(result.error, /update the Y4APK theme/);
});

test('endpoint() works for /wp-json and ?rest_route= roots', () => {
  assert.equal(endpoint({ apiRoot: 'https://site.example/wp-json' }, '/y4apk/v1/sync/run'), 'https://site.example/wp-json/y4apk/v1/sync/run');
  assert.equal(endpoint({ apiRoot: 'https://site.example/?rest_route=' }, '/y4apk/v1/sync/run'), 'https://site.example/?rest_route=/y4apk/v1/sync/run');
});

test('loadConfig needs a site and credentials', () => {
  assert.throws(() => loadConfig({ WP_SYNC_KEY: 'k' }), /WP_URL/);
  assert.throws(() => loadConfig({ WP_URL: 'https://site.example' }), /WP_SYNC_KEY/);
});

test('summarize', () => {
  assert.equal(summarize(RUN), '2 items matched to posts (1 newer versions), 1 without a post; 1 new posts for Write with AI; '
    + 'announced post 4 → 3.5.0; Write with AI: 1 in the queue (next by gemini)');
  assert.equal(summarize({ skipped: 'another run is working' }), 'skipped: another run is working');
  assert.equal(summarize({ items: {}, posts: {}, push: { caught_up: {}, sent: 0, waiting: 0 } }), 'nothing new');
});
