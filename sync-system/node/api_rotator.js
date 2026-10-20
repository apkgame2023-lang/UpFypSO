#!/usr/bin/env node
'use strict';

/**
 * api_rotator.js: part 3 of the app sync system.
 *
 * Sends HTTP requests with one of several API keys. It keeps using the active
 * key until the API pushes back, then fails over to the next usable key:
 *
 *   rate limited               429, or a 403 that names a rate limit or quota:
 *                              the key rests until Retry-After (or a retry
 *                              delay named in the body, or cooldownMs) has
 *                              passed, then comes back by itself
 *   quota used up              402, or a 429/403 about a daily limit, billing
 *                              or insufficient quota: the key rests at least
 *                              quotaCooldownMs (default one hour)
 *   key rejected               401, any other 403, or a 400 that says the key
 *                              is invalid: the key is retired for the life of
 *                              the process
 *   408, 5xx, network errors   not the key's fault: retried on the same key
 *                              with exponential backoff, up to maxRetries
 *   any other 4xx              the request's fault: thrown at once as ApiError
 *
 * When every key is resting, request() waits for the first one to come back
 * if that is within maxWaitMs, and otherwise throws AllKeysExhaustedError,
 * whose retryAt says when to try again. Key state lives in memory: give each
 * process its own set of keys, or expect each to learn the limits on its own.
 *
 * Pool only keys you are allowed to use together, such as keys of separate
 * paid projects or of a fallback provider. Many APIs' terms forbid extra keys
 * whose only purpose is to get around a per-account quota.
 *
 *   const { ApiKeyRotator } = require('./api_rotator');
 *   const api = new ApiKeyRotator({
 *     keys: process.env.API_KEYS.split(','),
 *     auth: { in: 'header', name: 'x-api-key' },
 *   });
 *   api.on('rotate', ({ from, to, reason }) => console.log(`${from} -> ${to}: ${reason}`));
 *   const { status, data, keyId } = await api.request('https://api.example.com/v1/items');
 *
 * From the command line, with API_KEYS (comma separated) in the environment:
 *   node api_rotator.js <url> [GET|POST] [json-body]
 */

const fs = require('node:fs');
const path = require('node:path');
const { EventEmitter } = require('node:events');

const EXHAUSTED_PATTERN = /insufficient_quota|billing|credit balance|per[ _-]?day|daily|\b(?:rpd|tpd)\b/i;
const LIMIT_PATTERN = /rate[ _-]?limit|too many requests|quota|resource[_ ]?exhausted|limit exceeded/i;
const INVALID_KEY_PATTERN = /api[ _-]?key[^.]{0,40}(?:invalid|not valid|expired|revoked|incorrect)|(?:invalid|incorrect|expired|revoked)[ _-]api[ _-]?key|api_key_invalid|unauthenticated/i;
const RETRY_IN_BODY = /(?:re)?try(?:[ _-]?delay"?\s*[:=]\s*"?|\s+(?:again\s+)?in\s+)(\d+(?:\.\d+)?)\s*s\b/i;

class ApiError extends Error {
  constructor(message, { status = null, data = null, keyId = null, cause } = {}) {
    super(message, cause ? { cause } : undefined);
    this.name = 'ApiError';
    this.status = status;
    this.data = data;
    this.keyId = keyId;
  }
}

class AllKeysExhaustedError extends ApiError {
  constructor(message, { retryAt = null, keys = [] } = {}) {
    super(message, { status: 429 });
    this.name = 'AllKeysExhaustedError';
    this.retryAt = retryAt; // Date, or null when every key was retired
    this.keys = keys;
  }
}

function maskKey(key) {
  return key.length > 12 ? `${key.slice(0, 4)}…${key.slice(-4)}` : `…${key.slice(-2)}`;
}

function sleep(ms, signal) {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) return reject(signal.reason);
    const timer = setTimeout(done, ms);
    function done() {
      signal?.removeEventListener('abort', aborted);
      resolve();
    }
    function aborted() {
      clearTimeout(timer);
      reject(signal.reason);
    }
    signal?.addEventListener('abort', aborted, { once: true });
  });
}

/** Retry-After as milliseconds (seconds or an HTTP date), else a retry delay named in the body, else null. */
function retryAfterMs(headers, bodyText, now = Date.now()) {
  const header = headers.get('retry-after');
  if (header) {
    const seconds = Number(header);
    if (Number.isFinite(seconds) && seconds >= 0) return seconds * 1000;
    const date = Date.parse(header);
    if (!Number.isNaN(date)) return Math.max(0, date - now);
  }
  const inBody = RETRY_IN_BODY.exec(bodyText || '');
  return inBody ? Math.ceil(Number(inBody[1]) * 1000) : null;
}

/** What a response means for the key that sent it. */
function classify(status, bodyText) {
  const body = bodyText || '';
  if (status < 400) return 'ok';
  if (status === 402) return 'quota';
  if (status === 429) return EXHAUSTED_PATTERN.test(body) ? 'quota' : 'rate_limited';
  if (status === 403) {
    if (!LIMIT_PATTERN.test(body)) return 'invalid_key';
    return EXHAUSTED_PATTERN.test(body) ? 'quota' : 'rate_limited';
  }
  if (status === 401) return 'invalid_key';
  if (status === 400 && INVALID_KEY_PATTERN.test(body)) return 'invalid_key';
  if (status === 408 || status >= 500) return 'transient';
  return 'client_error';
}

function parseBody(text, contentType) {
  if (!text) return null;
  if (/\bjson\b/i.test(contentType || '')) {
    try {
      return JSON.parse(text);
    } catch {
      return text;
    }
  }
  return text;
}

class ApiKeyRotator extends EventEmitter {
  /**
   * @param {object} options
   * @param {string[]} options.keys            API keys, tried in this order
   * @param {object} [options.auth]            where the key goes: { in: 'header', name, prefix }
   *                                           or { in: 'query', name }; default "Authorization: Bearer <key>"
   * @param {number} [options.timeoutMs]       per attempt (15 s)
   * @param {number} [options.maxRetries]      same-key retries for 408/5xx/network errors (2)
   * @param {number} [options.cooldownMs]      rest after a 429 without Retry-After (60 s)
   * @param {number} [options.quotaCooldownMs] rest after a quota error (1 h)
   * @param {number} [options.maxCooldownMs]   longest rest any answer can impose (24 h)
   * @param {number} [options.maxWaitMs]       how long request() may wait for a resting key (30 s)
   * @param {number} [options.maxAttempts]     attempts per request() across keys (keys × 2 + maxRetries)
   * @param {Function} [options.fetch]         fetch implementation (globalThis.fetch)
   * @param {object|null} [options.logger]     { warn, info }; null for silence (console)
   */
  constructor(options = {}) {
    super();
    const keys = [...new Set((options.keys || []).map((key) => String(key).trim()).filter(Boolean))];
    if (!keys.length) throw new Error('ApiKeyRotator needs at least one API key');

    this.auth = { in: 'header', name: 'Authorization', ...options.auth };
    if (!['header', 'query'].includes(this.auth.in) || !this.auth.name) {
      throw new Error("auth must be { in: 'header' | 'query', name: '...' }");
    }
    // "Authorization: Bearer <key>", but "x-api-key: <key>"
    this.auth.prefix ??= this.auth.in === 'header' && this.auth.name.toLowerCase() === 'authorization' ? 'Bearer ' : '';

    this.timeoutMs = options.timeoutMs ?? 15_000;
    this.maxRetries = options.maxRetries ?? 2;
    this.cooldownMs = options.cooldownMs ?? 60_000;
    this.quotaCooldownMs = options.quotaCooldownMs ?? 60 * 60_000;
    this.maxCooldownMs = options.maxCooldownMs ?? 24 * 60 * 60_000;
    this.maxWaitMs = options.maxWaitMs ?? 30_000;
    this.maxAttempts = options.maxAttempts ?? keys.length * 2 + this.maxRetries;
    this.fetch = options.fetch || globalThis.fetch;
    this.logger = options.logger === undefined ? console : options.logger;
    if (typeof this.fetch !== 'function') throw new Error('No fetch available: use Node 22+ or pass options.fetch');

    this.keys = keys.map((key, index) => ({
      key,
      id: `key#${index + 1} (${maskKey(key)})`,
      state: 'active', // active | resting | retired
      availableAt: 0,
      uses: 0,
      failures: 0,
      lastError: null,
    }));
    this.current = 0;
  }

  /** Key states without the keys themselves, for logs and health checks. */
  status() {
    const now = Date.now();
    return this.keys.map((k, index) => ({
      id: k.id,
      active: index === this.current && this.#usable(k, now),
      state: k.state === 'resting' && k.availableAt <= now ? 'active' : k.state,
      availableAt: k.state === 'resting' ? new Date(k.availableAt) : null,
      uses: k.uses,
      failures: k.failures,
      lastError: k.lastError,
    }));
  }

  /**
   * Sends one request, failing over between keys as described at the top of
   * this file. init is fetch's, plus `json` (sent as the JSON body). The body
   * must be re-sendable (string, Buffer, URLSearchParams…), not a stream.
   * Resolves { status, headers, data, keyId } for 1xx-3xx answers.
   */
  async request(url, init = {}) {
    const { json, ...fetchInit } = init;
    if (json !== undefined) {
      fetchInit.body = JSON.stringify(json);
      fetchInit.headers = new Headers(fetchInit.headers);
      if (!fetchInit.headers.has('content-type')) fetchInit.headers.set('content-type', 'application/json');
    }

    let retries = 0;
    for (let attempt = 1; ; attempt++) {
      if (attempt > this.maxAttempts) {
        throw new AllKeysExhaustedError(`Gave up after ${this.maxAttempts} attempts across the API keys`, {
          retryAt: this.#nextAvailableAt(), keys: this.status(),
        });
      }
      const entry = await this.#activeKey(fetchInit.signal);

      let response;
      let bodyText;
      try {
        response = await this.#send(url, fetchInit, entry.key);
        bodyText = await response.text();
      } catch (err) {
        if (fetchInit.signal?.aborted) throw err;
        entry.failures += 1;
        entry.lastError = err.name === 'TimeoutError' ? `timed out after ${this.timeoutMs} ms` : err.message;
        if (retries < this.maxRetries) {
          retries += 1;
          await sleep(this.#backoff(retries), fetchInit.signal);
          continue;
        }
        throw new ApiError(`Request failed after ${retries + 1} tries: ${entry.lastError}`, {
          keyId: entry.id, cause: err,
        });
      }

      const kind = classify(response.status, bodyText);
      const data = parseBody(bodyText, response.headers.get('content-type'));
      if (kind === 'ok') {
        entry.uses += 1;
        return { status: response.status, headers: response.headers, data, keyId: entry.id };
      }

      entry.failures += 1;
      entry.lastError = `HTTP ${response.status}: ${String(bodyText).slice(0, 200)}`;
      const waitMs = retryAfterMs(response.headers, bodyText);

      if (kind === 'rate_limited') {
        this.#rest(entry, waitMs ?? this.cooldownMs, `rate limited (HTTP ${response.status})`);
      } else if (kind === 'quota') {
        this.#rest(entry, Math.max(waitMs ?? 0, this.quotaCooldownMs), `quota used up (HTTP ${response.status})`);
      } else if (kind === 'invalid_key') {
        this.#retire(entry, `key rejected (HTTP ${response.status})`);
      } else if (kind === 'transient' && retries < this.maxRetries) {
        retries += 1;
        await sleep(Math.min(waitMs ?? this.#backoff(retries), this.maxWaitMs), fetchInit.signal);
      } else {
        throw new ApiError(`HTTP ${response.status} from ${new URL(url).host}`, {
          status: response.status, data, keyId: entry.id,
        });
      }
    }
  }

  #usable(entry, now) {
    return entry.state === 'active' || (entry.state === 'resting' && entry.availableAt <= now);
  }

  /** The current key if usable, else the next usable one; waits when all rest. */
  async #activeKey(signal) {
    for (;;) {
      const now = Date.now();
      for (let step = 0; step < this.keys.length; step++) {
        const index = (this.current + step) % this.keys.length;
        const entry = this.keys[index];
        if (!this.#usable(entry, now)) continue;
        if (entry.state === 'resting') {
          entry.state = 'active';
          this.#log('info', `${entry.id} is back from its rest`);
        }
        if (index !== this.current) {
          const from = this.keys[this.current];
          this.current = index;
          this.#log('warn', `Switched API key: ${from.id} -> ${entry.id}`);
          this.emit('rotate', { from: from.id, to: entry.id, reason: from.lastError });
        }
        return entry;
      }

      const retryAt = this.#nextAvailableAt();
      if (!retryAt) {
        throw new AllKeysExhaustedError('Every API key was rejected', { keys: this.status() });
      }
      const waitMs = retryAt.getTime() - now;
      if (waitMs > this.maxWaitMs) {
        throw new AllKeysExhaustedError(`Every API key is resting; the first is back at ${retryAt.toISOString()}`, {
          retryAt, keys: this.status(),
        });
      }
      this.#log('warn', `Every API key is resting; waiting ${Math.ceil(waitMs / 1000)} s`);
      await sleep(waitMs, signal);
    }
  }

  #nextAvailableAt() {
    const times = this.keys.filter((k) => k.state !== 'retired').map((k) => (k.state === 'active' ? 0 : k.availableAt));
    return times.length ? new Date(Math.max(Date.now(), Math.min(...times))) : null;
  }

  #rest(entry, ms, reason) {
    const until = Date.now() + Math.min(Math.max(ms, 1_000), this.maxCooldownMs);
    entry.state = 'resting';
    entry.availableAt = Math.max(entry.availableAt, until);
    this.#log('warn', `${entry.id} ${reason}; resting until ${new Date(entry.availableAt).toISOString()}`);
    this.emit('cooldown', { keyId: entry.id, until: new Date(entry.availableAt), reason });
  }

  #retire(entry, reason) {
    entry.state = 'retired';
    this.#log('warn', `${entry.id} ${reason}; no longer used`);
    this.emit('retired', { keyId: entry.id, reason });
  }

  #backoff(retry) {
    const base = Math.min(250 * 2 ** (retry - 1), 8_000);
    return base / 2 + Math.random() * (base / 2); // jittered, so parallel retries spread out
  }

  async #send(url, init, key) {
    const target = new URL(url);
    const headers = new Headers(init.headers);
    if (this.auth.in === 'query') target.searchParams.set(this.auth.name, key);
    else headers.set(this.auth.name, `${this.auth.prefix}${key}`);

    const timeout = AbortSignal.timeout(this.timeoutMs);
    const signal = init.signal ? AbortSignal.any([init.signal, timeout]) : timeout;
    return this.fetch(target, { ...init, headers, signal });
  }

  #log(level, message) {
    this.logger?.[level]?.(`[api_rotator] ${message}`);
  }
}

/* ------------------------------------------------------------------ *
 * Command line
 * ------------------------------------------------------------------ */

async function main() {
  const envFile = path.join(__dirname, '..', '.env');
  if (fs.existsSync(envFile)) process.loadEnvFile(envFile);

  const [url, method = 'GET', body] = process.argv.slice(2);
  if (!url) {
    console.error('Usage: node api_rotator.js <url> [GET|POST|...] [json-body]   (keys from API_KEYS)');
    process.exit(64);
  }
  const where = (process.env.API_KEY_IN || 'bearer').toLowerCase();
  const auth = where === 'query'
    ? { in: 'query', name: process.env.API_KEY_NAME || 'key' }
    : where === 'header'
      ? { in: 'header', name: process.env.API_KEY_NAME || 'x-api-key' }
      : { in: 'header', name: 'Authorization', prefix: 'Bearer ' };

  const api = new ApiKeyRotator({ keys: (process.env.API_KEYS || '').split(','), auth });
  try {
    const result = await api.request(url, { method: method.toUpperCase(), json: body ? JSON.parse(body) : undefined });
    console.log(JSON.stringify({ status: result.status, keyId: result.keyId, data: result.data }, null, 2));
  } catch (err) {
    console.error(`${err.name}: ${err.message}`);
    if (err.data) console.error(typeof err.data === 'string' ? err.data : JSON.stringify(err.data, null, 2));
    process.exitCode = 1;
  } finally {
    console.error(JSON.stringify(api.status(), null, 2));
  }
}

if (require.main === module) {
  main();
}

module.exports = { ApiKeyRotator, ApiError, AllKeysExhaustedError, classify, retryAfterMs, maskKey };
