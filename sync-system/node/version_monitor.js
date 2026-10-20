#!/usr/bin/env node
'use strict';

/**
 * version_monitor.js: part 2 of the app sync system.
 *
 * Every MONITOR_INTERVAL_SECONDS it reads the apps that app_scraper.py keeps
 * in MySQL and compares each app's latest_version with its notified_version,
 * the last version subscribers were told about. For every app whose version
 * went up, it sends a push notification through the Firebase Admin SDK to the
 * FCM topic "<FCM_TOPIC_PREFIX><app id>" (clients subscribe to the apps they
 * follow) and records the attempt in push_notifications.
 *
 *   - An app the monitor has never seen is new, not updated: its version
 *     becomes the baseline, with no notification.
 *   - The same version written differently ("v1.2" / "1.2.0"), a lower version
 *     or text without a number is adopted silently.
 *   - A failed push is retried on later runs, up to MONITOR_MAX_ATTEMPTS times
 *     per app and version.
 *   - Delivery is at least once: if the process dies between the push and the
 *     database update, the next run sends that push again.
 *
 * PUSH_MODE sets how real the push is:
 *   log      no Firebase at all; the message is only logged
 *   dry-run  Firebase checks the credentials and the message, delivers nothing (default)
 *   live     the message is delivered
 *
 * Only one monitor works at a time per database (MySQL GET_LOCK), so running
 * several copies, or a cron job next to the daemon, is safe.
 *
 * Usage:
 *   node version_monitor.js           run every MONITOR_INTERVAL_SECONDS until stopped
 *   node version_monitor.js --once    one pass, e.g. from cron; exit code 1 if it failed
 */

const fs = require('node:fs');
const path = require('node:path');
const mysql = require('mysql2/promise');

const ENV_FILE = path.join(__dirname, '..', '.env');
const PUSH_MODES = ['log', 'dry-run', 'live'];
const TOPIC_PATTERN = /^[a-zA-Z0-9\-_.~%]+$/;
const PUSH_TTL_MS = 2 * 24 * 60 * 60 * 1000;

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

function loadConfig() {
  if (fs.existsSync(ENV_FILE)) process.loadEnvFile(ENV_FILE);

  const missing = ['MYSQL_USER', 'MYSQL_DATABASE'].filter((name) => !process.env[name]);
  if (missing.length) throw new Error(`Set ${missing.join(', ')} (see .env.example)`);

  const pushMode = (process.env.PUSH_MODE || 'dry-run').trim().toLowerCase();
  if (!PUSH_MODES.includes(pushMode)) {
    throw new Error(`PUSH_MODE must be one of ${PUSH_MODES.join(', ')}, not "${pushMode}"`);
  }
  const topicPrefix = process.env.FCM_TOPIC_PREFIX ?? 'app_';
  if (topicPrefix && !TOPIC_PATTERN.test(topicPrefix)) {
    throw new Error('FCM_TOPIC_PREFIX may only use letters, digits and - _ . ~ %');
  }

  return {
    db: {
      host: process.env.MYSQL_HOST || '127.0.0.1',
      port: envInt('MYSQL_PORT', 3306),
      user: process.env.MYSQL_USER,
      password: process.env.MYSQL_PASSWORD || '',
      database: process.env.MYSQL_DATABASE,
      charset: 'utf8mb4',
      timezone: 'Z',
      connectionLimit: 4,
      connectTimeout: 10_000,
    },
    intervalMs: envInt('MONITOR_INTERVAL_SECONDS', 300) * 1000,
    batchSize: envInt('MONITOR_BATCH_SIZE', 200),
    maxAttempts: envInt('MONITOR_MAX_ATTEMPTS', 3),
    pushMode,
    topicPrefix,
    serviceAccount: process.env.FIREBASE_SERVICE_ACCOUNT || '',
  };
}

/* ------------------------------------------------------------------ *
 * Versions
 * ------------------------------------------------------------------ */

const PRERELEASE_RANK = { dev: 0, alpha: 1, a: 1, beta: 2, b: 2, pre: 3, preview: 3, rc: 4 };

/**
 * The comparable parts of a version string: "v2.10.0-beta.3 (Android)" →
 * { numbers: [2, 10], pre: { rank: 2, number: 3 } }. Trailing zeros are
 * dropped, so "1.2" and "1.2.0" are equal. null when there is no number.
 */
function parseVersion(text) {
  const value = String(text ?? '');
  const match = /\d+(?:\.\d+)*/.exec(value);
  if (!match) return null;

  const numbers = match[0].split('.').map(Number);
  while (numbers.length > 1 && numbers[numbers.length - 1] === 0) numbers.pop();

  const rest = value.slice(match.index + match[0].length);
  const tag = /^[\s._-]*(dev|alpha|beta|preview|pre|rc|a|b)(?![a-z])[\s._-]*(\d+)?/i.exec(rest);
  const pre = tag ? { rank: PRERELEASE_RANK[tag[1].toLowerCase()], number: Number(tag[2] ?? 0) } : null;
  return { numbers, pre };
}

/**
 * 1 when a is newer than b, -1 when older, 0 when the same version, and null
 * when either has no number to compare. "2.10" > "2.9", "1.0" > "1.0-rc2" >
 * "1.0-beta".
 */
function compareVersions(a, b) {
  const x = parseVersion(a);
  const y = parseVersion(b);
  if (!x || !y) return null;

  const length = Math.max(x.numbers.length, y.numbers.length);
  for (let i = 0; i < length; i++) {
    const diff = (x.numbers[i] ?? 0) - (y.numbers[i] ?? 0);
    if (diff !== 0) return Math.sign(diff);
  }
  if (!x.pre && !y.pre) return 0;
  if (!x.pre) return 1; // a release is newer than its pre-releases
  if (!y.pre) return -1;
  return Math.sign(x.pre.rank - y.pre.rank || x.pre.number - y.pre.number);
}

/* ------------------------------------------------------------------ *
 * Push
 * ------------------------------------------------------------------ */

function truncate(text, max) {
  return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

function topicFor(appId, prefix) {
  const topic = `${prefix}${appId}`;
  if (!TOPIC_PATTERN.test(topic)) throw new Error(`Invalid FCM topic "${topic}"`);
  return topic;
}

/** The FCM message announcing app's new version. FCM data values must be strings. */
function buildMessage(app, topicPrefix) {
  const from = app.notified_version;
  const to = app.latest_version;
  const collapse = `app_update_${app.id}`;
  const message = {
    topic: topicFor(app.id, topicPrefix),
    notification: {
      title: truncate(`${app.name} updated`, 100),
      body: truncate(`Version ${to} is available (was ${from}).`, 200),
    },
    data: {
      type: 'app_update',
      appId: String(app.id),
      name: app.name,
      version: to,
      previousVersion: from,
      link: app.link,
    },
    android: { priority: 'high', collapseKey: collapse, ttl: PUSH_TTL_MS },
    apns: {
      headers: { 'apns-collapse-id': collapse },
      payload: { aps: { sound: 'default' } },
    },
  };
  if (/^https:\/\//i.test(app.link)) {
    message.webpush = { fcmOptions: { link: app.link } }; // FCM accepts HTTPS links only
  }
  return message;
}

/** { send(message) → message id, close() } for the configured PUSH_MODE. */
function createPushSender(config) {
  if (config.pushMode === 'log') {
    let sequence = 0;
    return {
      async send(message) {
        logger.info(`[push:log] ${JSON.stringify(message)}`);
        sequence += 1;
        return `log/${Date.now()}-${sequence}`;
      },
      async close() {},
    };
  }

  const { initializeApp, cert, applicationDefault, deleteApp } = require('firebase-admin/app');
  const { getMessaging } = require('firebase-admin/messaging');
  // Without FIREBASE_SERVICE_ACCOUNT, Application Default Credentials are used
  // (GOOGLE_APPLICATION_CREDENTIALS, or the service account of a Google Cloud host).
  const credential = config.serviceAccount
    ? cert(path.resolve(path.join(__dirname, '..'), config.serviceAccount))
    : applicationDefault();
  const app = initializeApp({ credential }, 'version-monitor');
  const messaging = getMessaging(app);
  const dryRun = config.pushMode === 'dry-run';

  return {
    send: (message) => messaging.send(message, dryRun),
    close: () => deleteApp(app),
  };
}

/* ------------------------------------------------------------------ *
 * One pass
 * ------------------------------------------------------------------ */

const CANDIDATES_SQL = `
  SELECT a.id, a.name, a.link, a.latest_version, a.notified_version,
         (SELECT COUNT(*) FROM push_notifications p
           WHERE p.app_id = a.id AND p.to_version = a.latest_version AND p.status = 'failed') AS failures
    FROM apps a
   WHERE a.latest_version <> ''
     AND (a.notified_version IS NULL OR a.notified_version <> a.latest_version)
  HAVING failures < ?
   ORDER BY a.version_changed_at IS NULL, a.version_changed_at, a.id
   LIMIT ?`;

// The latest_version guard skips the update when the scraper wrote a newer
// version meanwhile; the next run handles that one.
const ADOPT_SQL = `
  UPDATE apps SET notified_version = latest_version
   WHERE id = ? AND latest_version = ?`;

const NOTIFIED_SQL = `
  UPDATE apps SET notified_version = ?, notified_at = UTC_TIMESTAMP()
   WHERE id = ? AND latest_version = ?`;

const LOG_SQL = `
  INSERT INTO push_notifications (app_id, from_version, to_version, topic, mode, status, message_id, error)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?)`;

function lockName(database) {
  return `version_monitor:${database}`.slice(0, 64);
}

async function withLock(pool, name, work) {
  const conn = await pool.getConnection();
  try {
    await conn.query("SET time_zone = '+00:00'");
    const [[{ locked }]] = await conn.query('SELECT GET_LOCK(?, 0) AS locked', [name]);
    if (locked !== 1) return { skipped: true };
    try {
      return await work(conn);
    } finally {
      await conn.query('SELECT RELEASE_LOCK(?)', [name]);
    }
  } finally {
    conn.release();
  }
}

async function notify(conn, app, push, config) {
  const message = buildMessage(app, config.topicPrefix);
  let messageId;
  try {
    messageId = await push.send(message);
  } catch (err) {
    const error = truncate(`${err.code ? `${err.code}: ` : ''}${err.message}`, 2000);
    await conn.query(LOG_SQL, [
      app.id, app.notified_version, app.latest_version, message.topic, config.pushMode, 'failed', null, error,
    ]);
    logger.warn(`Push for ${app.name} ${app.latest_version} failed (attempt ${Number(app.failures) + 1}`
      + ` of ${config.maxAttempts}): ${error}`);
    return false;
  }

  await conn.beginTransaction();
  try {
    await conn.query(LOG_SQL, [
      app.id, app.notified_version, app.latest_version, message.topic, config.pushMode, 'sent', messageId, null,
    ]);
    await conn.query(NOTIFIED_SQL, [app.latest_version, app.id, app.latest_version]);
    await conn.commit();
  } catch (err) {
    await conn.rollback();
    throw err;
  }
  logger.info(`Notified ${message.topic}: ${app.name} ${app.notified_version} -> ${app.latest_version}`
    + ` (${config.pushMode}, ${messageId})`);
  return true;
}

/**
 * Checks one batch of apps and notifies the updated ones. Returns counts:
 * { checked, baselined, adopted, sent, failed }, or { skipped: true } when
 * another monitor holds the lock.
 */
async function runOnce(pool, push, config) {
  return withLock(pool, lockName(config.db.database), async (conn) => {
    const stats = { checked: 0, baselined: 0, adopted: 0, sent: 0, failed: 0 };
    const [apps] = await conn.query(CANDIDATES_SQL, [config.maxAttempts, config.batchSize]);

    for (const app of apps) {
      stats.checked += 1;
      if (app.notified_version === null) {
        await conn.query(ADOPT_SQL, [app.id, app.latest_version]);
        stats.baselined += 1;
        continue;
      }

      const order = compareVersions(app.latest_version, app.notified_version);
      if (order === null || order <= 0) {
        const why = order === null ? 'no comparable number' : order === 0 ? 'same version' : 'lower version';
        logger.info(`Not announcing ${app.name} ${app.notified_version} -> ${app.latest_version} (${why})`);
        await conn.query(ADOPT_SQL, [app.id, app.latest_version]);
        stats.adopted += 1;
        continue;
      }

      if (await notify(conn, app, push, config)) stats.sent += 1;
      else stats.failed += 1;
    }
    return stats;
  });
}

/* ------------------------------------------------------------------ *
 * Scheduler
 * ------------------------------------------------------------------ */

async function main() {
  const once = process.argv.includes('--once');
  const config = loadConfig();
  const pool = mysql.createPool(config.db);
  const push = createPushSender(config);

  let timer = null;
  let current = null;
  let stopping = false;

  const pass = async () => {
    try {
      const stats = await runOnce(pool, push, config);
      if (stats.skipped) logger.info('Another monitor is running; skipped this pass');
      else if (stats.checked) logger.info('Pass done', stats);
      return !stats.failed;
    } catch (err) {
      logger.error(`Pass failed: ${err.stack || err.message}`);
      return false;
    }
  };

  const close = async () => {
    await Promise.allSettled([pool.end(), push.close()]);
  };

  if (once) {
    const ok = await pass();
    await close();
    process.exitCode = ok ? 0 : 1;
    return;
  }

  const tick = async () => {
    current = pass();
    await current;
    current = null;
    if (!stopping) timer = setTimeout(tick, config.intervalMs);
  };

  const shutdown = async (signal) => {
    if (stopping) return;
    stopping = true;
    logger.info(`${signal}: stopping after the current pass`);
    clearTimeout(timer);
    if (current) await current;
    await close();
    process.exit(0);
  };
  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('SIGTERM', () => shutdown('SIGTERM'));

  logger.info(`Version monitor started: every ${config.intervalMs / 1000}s, PUSH_MODE=${config.pushMode}`);
  await tick();
}

if (require.main === module) {
  main().catch((err) => {
    logger.error(err.message);
    process.exit(1);
  });
}

module.exports = { parseVersion, compareVersions, buildMessage, createPushSender, runOnce, loadConfig };
