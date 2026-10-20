'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { ApiKeyRotator, AllKeysExhaustedError, ApiError, classify, retryAfterMs } = require('../api_rotator');

/** A local API whose answer depends on the key: behaviour[key](req, attempt) → [status, headers, body]. */
async function startServer(behaviour) {
  const calls = [];
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const key = (req.headers.authorization || '').replace(/^Bearer /, '')
      || req.headers['x-api-key'] || url.searchParams.get('key');
    calls.push(key);
    const handler = behaviour[key] || (() => [401, {}, { error: 'unknown key' }]);
    const [status, headers, body] = handler(req, calls.filter((k) => k === key).length);
    res.writeHead(status, { 'content-type': 'application/json', ...headers });
    res.end(JSON.stringify(body));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const url = `http://127.0.0.1:${server.address().port}/v1/items`;
  return { url, calls, close: () => new Promise((resolve) => server.close(resolve)) };
}

const ok = () => [200, {}, { items: [1, 2, 3] }];
const quiet = { logger: null };

test('fails over to the next key on 429 and keeps using it', async (t) => {
  const api = await startServer({
    'key-A': () => [429, { 'retry-after': '120' }, { error: 'Too Many Requests' }],
    'key-B': ok,
  });
  t.after(api.close);
  const rotator = new ApiKeyRotator({ keys: ['key-A', 'key-B'], ...quiet });
  const rotations = [];
  rotator.on('rotate', (event) => rotations.push(event));

  const first = await rotator.request(api.url);
  assert.equal(first.status, 200);
  assert.deepEqual(first.data, { items: [1, 2, 3] });
  assert.match(first.keyId, /^key#2/);

  await rotator.request(api.url);
  assert.deepEqual(api.calls, ['key-A', 'key-B', 'key-B']); // B stays active
  assert.equal(rotations.length, 1);

  const [a, b] = rotator.status();
  assert.equal(a.state, 'resting');
  assert.ok(a.availableAt - Date.now() > 110_000, 'Retry-After of 120 s is honoured');
  assert.equal(b.active, true);
  assert.equal(b.uses, 2);
});

test('a daily quota rests the key at least quotaCooldownMs', async (t) => {
  const api = await startServer({
    'key-A': () => [429, {}, { error: { status: 'RESOURCE_EXHAUSTED', message: 'Quota exceeded: requests per day' } }],
    'key-B': ok,
  });
  t.after(api.close);
  const rotator = new ApiKeyRotator({ keys: ['key-A', 'key-B'], quotaCooldownMs: 3_600_000, ...quiet });
  await rotator.request(api.url);
  assert.ok(rotator.status()[0].availableAt - Date.now() > 3_500_000);
});

test('a rejected key is retired for good', async (t) => {
  const api = await startServer({ 'key-A': () => [401, {}, { error: 'invalid api key' }], 'key-B': ok });
  t.after(api.close);
  const rotator = new ApiKeyRotator({ keys: ['key-A', 'key-B'], ...quiet });
  const retired = [];
  rotator.on('retired', (event) => retired.push(event));
  await rotator.request(api.url);
  assert.equal(rotator.status()[0].state, 'retired');
  assert.equal(retired.length, 1);
});

test('throws AllKeysExhaustedError with retryAt when every key rests longer than maxWaitMs', async (t) => {
  const limited = () => [429, { 'retry-after': '600' }, { error: 'slow down' }];
  const api = await startServer({ 'key-A': limited, 'key-B': limited });
  t.after(api.close);
  const rotator = new ApiKeyRotator({ keys: ['key-A', 'key-B'], maxWaitMs: 1_000, ...quiet });
  await assert.rejects(rotator.request(api.url), (err) => {
    assert.ok(err instanceof AllKeysExhaustedError);
    assert.ok(err.retryAt instanceof Date && err.retryAt - Date.now() > 590_000);
    assert.equal(err.keys.length, 2);
    return true;
  });
  assert.deepEqual(api.calls, ['key-A', 'key-B']);
});

test('waits for a resting key when it is back within maxWaitMs', async (t) => {
  const api = await startServer({
    'key-A': (req, n) => (n === 1 ? [429, { 'retry-after': '1' }, { error: 'slow down' }] : ok()),
  });
  t.after(api.close);
  const rotator = new ApiKeyRotator({ keys: ['key-A'], maxWaitMs: 5_000, ...quiet });
  const started = Date.now();
  const result = await rotator.request(api.url);
  assert.equal(result.status, 200);
  assert.ok(Date.now() - started >= 900);
});

test('retries 5xx on the same key, then succeeds', async (t) => {
  const api = await startServer({
    'key-A': (req, n) => (n < 3 ? [503, {}, { error: 'unavailable' }] : ok()),
    'key-B': ok,
  });
  t.after(api.close);
  const rotator = new ApiKeyRotator({ keys: ['key-A', 'key-B'], maxRetries: 2, ...quiet });
  const result = await rotator.request(api.url);
  assert.match(result.keyId, /^key#1/);
  assert.deepEqual(api.calls, ['key-A', 'key-A', 'key-A']);
});

test('other 4xx errors are thrown without rotating', async (t) => {
  const api = await startServer({ 'key-A': () => [404, {}, { error: 'no such item' }], 'key-B': ok });
  t.after(api.close);
  const rotator = new ApiKeyRotator({ keys: ['key-A', 'key-B'], ...quiet });
  await assert.rejects(rotator.request(api.url), (err) => {
    assert.ok(err instanceof ApiError && !(err instanceof AllKeysExhaustedError));
    assert.equal(err.status, 404);
    assert.deepEqual(err.data, { error: 'no such item' });
    return true;
  });
  assert.deepEqual(api.calls, ['key-A']);
});

test('puts the key in a custom header or the query string', async (t) => {
  const api = await startServer({ 'key-A': ok });
  t.after(api.close);
  await new ApiKeyRotator({ keys: ['key-A'], auth: { in: 'header', name: 'x-api-key' }, ...quiet }).request(api.url);
  await new ApiKeyRotator({ keys: ['key-A'], auth: { in: 'query', name: 'key' }, ...quiet }).request(api.url);
  assert.deepEqual(api.calls, ['key-A', 'key-A']);
});

test('sends a JSON body', async (t) => {
  let received;
  const api = await startServer({
    'key-A': (req) => {
      received = [req.headers['content-type'], req.headers['x-trace']];
      return ok();
    },
  });
  t.after(api.close);
  const rotator = new ApiKeyRotator({ keys: ['key-A'], ...quiet });
  await rotator.request(api.url, { method: 'POST', json: { name: 'x' }, headers: new Headers({ 'x-trace': '1' }) });
  assert.deepEqual(received, ['application/json', '1']);
});

test('classify', () => {
  assert.equal(classify(200, ''), 'ok');
  assert.equal(classify(429, 'Too Many Requests'), 'rate_limited');
  assert.equal(classify(429, 'You exceeded your current quota, please retry in 17s'), 'rate_limited');
  assert.equal(classify(429, '{"type":"insufficient_quota"}'), 'quota');
  assert.equal(classify(402, ''), 'quota');
  assert.equal(classify(403, 'User Rate Limit Exceeded'), 'rate_limited');
  assert.equal(classify(403, 'Daily Limit Exceeded'), 'quota');
  assert.equal(classify(403, 'The caller does not have permission'), 'invalid_key');
  assert.equal(classify(401, ''), 'invalid_key');
  assert.equal(classify(400, 'API key not valid. Please pass a valid API key.'), 'invalid_key');
  assert.equal(classify(400, 'missing field "name"'), 'client_error');
  assert.equal(classify(503, ''), 'transient');
});

test('retryAfterMs reads seconds, HTTP dates and delays named in the body', () => {
  const now = Date.parse('2026-01-01T00:00:00Z');
  assert.equal(retryAfterMs(new Headers({ 'retry-after': '30' }), ''), 30_000);
  assert.equal(retryAfterMs(new Headers({ 'retry-after': 'Thu, 01 Jan 2026 00:01:00 GMT' }), '', now), 60_000);
  assert.equal(retryAfterMs(new Headers(), '{"retryDelay": "17s"}'), 17_000);
  assert.equal(retryAfterMs(new Headers(), 'Please try again in 1.5s.'), 1_500);
  assert.equal(retryAfterMs(new Headers(), 'nothing here'), null);
});
