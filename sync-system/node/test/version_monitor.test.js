'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { compareVersions, buildMessage } = require('../version_monitor');

test('compareVersions', () => {
  const cases = [
    ['2.10', '2.9', 1],
    ['1.2.0', '1.2', 0],
    ['v1.2', '1.2', 0],
    ['v2.742.Android', '2.741', 1],
    ['1.0', '1.0-rc2', 1],
    ['1.0-rc2', '1.0-rc1', 1],
    ['1.0-rc1', '1.0-beta.3', 1],
    ['1.0b2', '1.0b10', -1],
    ['1.2 build 5', '1.2', 0],
    ['2026.10.01', '2026.9.30', 1],
    ['1.9', '1.10', -1],
    ['Varies with device', '1.0', null],
    ['', '1.0', null],
  ];
  for (const [a, b, expected] of cases) {
    assert.equal(compareVersions(a, b), expected, `${a} vs ${b}`);
  }
});

test('buildMessage', () => {
  const app = { id: 7, name: 'Sample App', link: 'https://example.com/apps/sample', latest_version: '2.0', notified_version: '1.9' };
  const message = buildMessage(app, 'app_');
  assert.equal(message.topic, 'app_7');
  assert.equal(message.notification.title, 'Sample App updated');
  assert.equal(message.notification.body, 'Version 2.0 is available (was 1.9).');
  assert.deepEqual(message.data, {
    type: 'app_update', appId: '7', name: 'Sample App', version: '2.0', previousVersion: '1.9',
    link: 'https://example.com/apps/sample',
  });
  assert.ok(Object.values(message.data).every((value) => typeof value === 'string'));
  assert.equal(message.webpush.fcmOptions.link, app.link);
  assert.equal(buildMessage({ ...app, link: 'http://example.com/x' }, 'app_').webpush, undefined);
  assert.throws(() => buildMessage(app, 'bad prefix '), /Invalid FCM topic/);
});
