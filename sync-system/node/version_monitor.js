#!/usr/bin/env node
'use strict';

/**
 * version_monitor.js: part 2 of the app sync system, for the Y4APK theme.
 *
 * Every MONITOR_INTERVAL_SECONDS it asks WordPress for one Catalog sync run
 * (POST /wp-json/y4apk/v1/sync/run, theme/y4apk/inc/sync.php), which:
 *
 *   - matches catalog items still waiting to posts and passes newer versions
 *     on to App updates ▸ Need a new file;
 *   - turns apps the site has no post for into drafts that Write with AI
 *     writes (when that is switched on in the theme);
 *   - compares each followed post's APK version with the last one its
 *     followers were told about and, when it went up, queues the theme's own
 *     Web Push (inc/push.php: VAPID, aes128gcm) and sends the queue.
 *
 * The comparison runs inside WordPress with push.php's own rule — the number
 * in the download's version, PHP's version_compare — the same one a save in
 * the post editor uses, so an update is never announced twice or by two rules.
 *
 * With MONITOR_WP_CRON=1 (the default) it then opens wp-cron.php, so the
 * theme's other background steps (Write with AI's queue every 5 minutes, App
 * updates' checks every 15) run on time even when a page cache keeps
 * WordPress's own cron from firing.
 *
 * Requests go through api_rotator.js (part 3): 429, 5xx and network errors
 * are retried with backoff, honouring Retry-After.
 *
 * Usage:
 *   node version_monitor.js            a run every MONITOR_INTERVAL_SECONDS until stopped
 *   node version_monitor.js --once     one run, e.g. from cron; exit code 1 if it failed
 *   node version_monitor.js --status   what WordPress has: items, last run, queues
 */

const fs = require('node:fs');
const path = require('node:path');
const { ApiKeyRotator, ApiError, AllKeysExhaustedError } = require('./api_rotator');

const ENV_FILE = path.join(__dirname, '..', '.env');

const logger = {
  info: (...args) => console.log(new Date().toISOString(), 'INFO ', ...args),
  warn: (...args) => console.warn(new Date().toISOString(), 'WARN ', ...args),
  error: (...args) => console.error(new Date().toISOString(), 'ERROR', ...args),
};

/* ------------------------------------------------------------------ *
 * Configuration
 * ------------------------------------------------------------------ */

function envInt(name, fallback, min = 1) {
  const raw = (process.env[name] ?? '').trim();
  if (raw === '') return fallback;
  const value = Number(raw);
  if (!Number.isInteger(value) || value < min) {
    throw new Error(`${name} must be a whole number of at least ${min}, not "${raw}"`);
  }
  return value;
}

function loadConfig(env = process.env) {
  if (env === process.env && fs.existsSync(ENV_FILE)) process.loadEnvFile(ENV_FILE);

  const site = (env.WP_URL || '').trim().replace(/\/+$/, '');
  const apiRoot = (env.WP_API_ROOT || '').trim() || (site ? `${site}/wp-json` : '');
  if (!apiRoot) throw new Error('Set WP_URL (your site\'s address) or WP_API_ROOT (see .env.example)');

  let auth;
  if ((env.WP_SYNC_KEY || '').trim()) {
    auth = { key: env.WP_SYNC_KEY.trim(), header: 'X-Y4APK-Sync-Key', prefix: '' };
  } else if (env.WP_USER && env.WP_APP_PASSWORD) {
    const token = Buffer.from(`${env.WP_USER}:${env.WP_APP_PASSWORD}`).toString('base64');
    auth = { key: token, header: 'Authorization', prefix: 'Basic ' };
  } else {
    throw new Error('Set WP_SYNC_KEY (App updates ▸ Settings ▸ Catalog sync), or WP_USER and WP_APP_PASSWORD');
  }

  return {
    site,
    apiRoot,
    auth,
    intervalMs: envInt('MONITOR_INTERVAL_SECONDS', 300) * 1000,
    timeoutMs: envInt('MONITOR_TIMEOUT_SECONDS', 120) * 1000,
    wpCron: (env.MONITOR_WP_CRON ?? '1').trim() !== '0' && site !== '',
    userAgent: (env.MONITOR_USER_AGENT || 'Y4APK-version-monitor/2.0').trim(),
  };
}

/** The address of a theme route, for both /wp-json and ?rest_route= roots. */
function endpoint(config, route) {
  return config.apiRoot.includes('rest_route=') ? `${config.apiRoot}${route}` : `${config.apiRoot.replace(/\/+$/, '')}${route}`;
}

/** A client for one run: the sync key (or Application Password) as the rotator's only key. */
function client(config, options = {}) {
  return new ApiKeyRotator({
    keys: [config.auth.key],
    auth: { in: 'header', name: config.auth.header, prefix: config.auth.prefix },
    timeoutMs: config.timeoutMs,
    maxRetries: 3,
    maxWaitMs: 60_000,
    logger: null,
    ...options,
  });
}

/* ------------------------------------------------------------------ *
 * One run
 * ------------------------------------------------------------------ */

/** One line about what WordPress did. */
function summarize(run) {
  if (run.skipped) return `skipped: ${run.skipped}`;
  const parts = [];
  const items = run.items || {};
  if (items.matched || items.nopost) {
    parts.push(`${items.matched} items matched to posts (${items.versions} newer versions), ${items.nopost} without a post`);
  }
  const posts = run.posts || {};
  if (posts.added) parts.push(`${posts.added} new posts for Write with AI`);
  if (posts.limit) parts.push('new posts: today\'s limit reached');
  const push = run.push;
  if (push) {
    const announced = Object.entries(push.caught_up || {});
    if (announced.length) {
      parts.push(`announced ${announced.map(([id, version]) => `post ${id} → ${version}`).join(', ')}`);
    }
    if (push.sent) parts.push(`${push.sent} queued notifications sent`);
    if (push.waiting) parts.push(`${push.waiting} notifications still queued`);
  }
  const writer = run.writer;
  if (writer && writer.queue) {
    const state = writer.paused ? `paused: ${writer.paused}` : writer.waiting ? `waiting: ${writer.waiting}` : `next by ${writer.ai}`;
    parts.push(`Write with AI: ${writer.queue} in the queue (${state})`);
  }
  return parts.length ? parts.join('; ') : 'nothing new';
}

/** Explains a failed request the way someone fixing .env needs it. */
function explain(err) {
  const said = err instanceof AllKeysExhaustedError ? String(err.keys?.[0]?.lastError || '') : '';
  if (said.includes('y4apk_sync_off')) {
    return 'Catalog sync is switched off on the site (App updates ▸ Settings ▸ Catalog sync)';
  }
  if (err instanceof AllKeysExhaustedError && !err.retryAt) {
    return 'WordPress refused the credentials: check WP_SYNC_KEY (App updates ▸ Settings ▸ Catalog sync) or WP_USER / WP_APP_PASSWORD';
  }
  if (err instanceof AllKeysExhaustedError) {
    return `WordPress is rate limiting; try again after ${err.retryAt.toISOString()}`;
  }
  if (err instanceof ApiError && err.status === 404) {
    return 'No Catalog sync route on this site: update the Y4APK theme to 2.30+, or check WP_URL / WP_API_ROOT';
  }
  if (err instanceof ApiError && err.data && typeof err.data === 'object' && err.data.message) {
    return `${err.message}: ${err.data.message}`;
  }
  return err.message;
}

/**
 * POST /sync/run, then wp-cron.php. Resolves { ok, run, cron }, where run is
 * WordPress's answer; never throws.
 */
async function runOnce(config, options = {}) {
  const api = client(config, options);
  let run;
  try {
    ({ data: run } = await api.request(endpoint(config, '/y4apk/v1/sync/run'), {
      method: 'POST',
      headers: { 'User-Agent': config.userAgent, Accept: 'application/json' },
    }));
  } catch (err) {
    logger.error(`Sync run failed: ${explain(err)}`);
    return { ok: false, error: explain(err) };
  }
  if (!run || typeof run !== 'object') {
    logger.error('Sync run failed: WordPress did not answer with JSON (a security plugin or cache in the way?)');
    return { ok: false, error: 'not JSON' };
  }
  logger.info(`Sync run: ${summarize(run)}`);

  let cron = null;
  if (config.wpCron) {
    try {
      // wp-cron.php runs the events that are due and needs no sign-in. doing_wp_cron
      // stays empty: WordPress then takes its own lock (a value it did not make
      // itself makes it do nothing); the parameter keeps page caches out of the way.
      const r = await (options.fetch || fetch)(`${config.site}/wp-cron.php?doing_wp_cron`, {
        headers: { 'User-Agent': config.userAgent },
        signal: AbortSignal.timeout(config.timeoutMs),
      });
      await r.arrayBuffer();
      cron = r.status;
      if (!r.ok) logger.warn(`wp-cron.php answered HTTP ${r.status}`);
    } catch (err) {
      logger.warn(`wp-cron.php: ${err.message}`);
    }
  }
  return { ok: true, run, cron };
}

async function status(config) {
  const { data } = await client(config).request(endpoint(config, '/y4apk/v1/sync/status'), {
    headers: { 'User-Agent': config.userAgent, Accept: 'application/json' },
  });
  return data;
}

/* ------------------------------------------------------------------ *
 * Scheduler
 * ------------------------------------------------------------------ */

async function main() {
  const config = loadConfig();

  if (process.argv.includes('--status')) {
    try {
      console.log(JSON.stringify(await status(config), null, 2));
    } catch (err) {
      logger.error(explain(err));
      process.exitCode = 1;
    }
    return;
  }

  if (process.argv.includes('--once')) {
    const result = await runOnce(config);
    process.exitCode = result.ok ? 0 : 1;
    return;
  }

  let timer = null;
  let current = null;
  let stopping = false;

  const tick = async () => {
    current = runOnce(config);
    await current;
    current = null;
    if (!stopping) timer = setTimeout(tick, config.intervalMs);
  };

  const shutdown = async (signal) => {
    if (stopping) return;
    stopping = true;
    logger.info(`${signal}: stopping after the current run`);
    clearTimeout(timer);
    if (current) await current;
    process.exit(0);
  };
  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('SIGTERM', () => shutdown('SIGTERM'));

  logger.info(`Version monitor started for ${config.site || config.apiRoot}: every ${config.intervalMs / 1000}s`
    + (config.wpCron ? ', wp-cron.php after each run' : ''));
  await tick();
}

if (require.main === module) {
  main().catch((err) => {
    logger.error(err.message);
    process.exit(1);
  });
}

module.exports = { loadConfig, endpoint, runOnce, status, summarize, explain };
