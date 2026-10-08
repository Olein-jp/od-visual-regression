import { spawnSync } from 'node:child_process';
import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright';
import { PNG } from 'pngjs';
import { DEFAULT_DEVICES, DEFAULT_SETTINGS } from '@odvr/shared';
import { SnapshotError, classifyError, safeUrl } from '../dist/errors.js';
import { validateDestination } from '../dist/security/url-validator.js';
import { installNetworkGuard } from '../dist/security/network-guard.js';
import { executeRun } from '../dist/jobs/execute-run.js';
const origin = 'https://8.8.8.8';
const secretUrl = `${origin}/?token=query-secret#fragment-secret`;
const secretError = new Error(`Authorization: Bearer bearer-secret Basic basic-secret Shared Secret shared-secret ${secretUrl}`);
function png() { return PNG.sync.write(new PNG({ width: 2, height: 2 })); }
function browserFixture(scenarios) {
  let index = 0;
  return {
    version: () => 'test', close: async () => {},
    newContext: async () => {
      const scenario = scenarios[index++];
      let crash, routeHandler;
      return {
        route: async (_, handler) => { routeHandler = handler; }, routeWebSocket: async () => {},
        close: async () => { if (scenario.cleanup) throw secretError; },
        newPage: async () => ({
          on: (event, listener) => { if (event === 'crash') crash = listener; },
          goto: async () => {
            if (scenario.blocked) {
              const frame = { page: () => ({ mainFrame: () => frame }) };
              await routeHandler({
                request: () => ({ url: () => scenario.redirect ? secretUrl : 'https://outside.example/?token=secret', isNavigationRequest: () => Boolean(scenario.redirect), frame: () => frame }),
                fetch: async () => ({ status: () => 302, dispose: async () => {} }),
                abort: async () => {}, fulfill: async () => {},
              });
              if (scenario.redirect) throw secretError;
            }
            if (scenario.crash) { crash(); throw secretError; }
            if (scenario.navigation) throw scenario.navigation;
            return { status: () => scenario.http ?? 200 };
          },
          addStyleTag: async () => {},
          waitForFunction: async () => { if (scenario.imageTimeout) throw secretError; },
          evaluate: async () => Boolean(scenario.brokenImage),
          locator: () => ({}),
          screenshot: async () => { if (scenario.screenshot) throw secretError; return png(); },
        }),
      };
    },
  };
}
test('元の例外を公開せず、DNS・TLS・Timeout・Crashを分類する', () => {
  for (const [message, code] of [['ENOTFOUND', 'DNS_ERROR'], ['EAI_AGAIN', 'DNS_ERROR'], ['net::ERR_CERT_AUTHORITY_INVALID', 'TLS_ERROR'], ['net::ERR_SSL_PROTOCOL_ERROR', 'TLS_ERROR'], ['Timeout 100ms exceeded', 'NAVIGATION_TIMEOUT'], ['Page crashed', 'PAGE_CRASH'], ['net::ERR_CONNECTION_REFUSED', 'NETWORK_ERROR']]) {
    const error = classifyError(new Error(`${message} ${secretError.message}`), 'NAVIGATION_TIMEOUT');
    assert.equal(error.code, code);
    assert.ok(!error.message.includes('secret'));
  }
  assert.equal(safeUrl('https://user:password@example.com/path?key=secret#secret'), 'https://example.com/path');
  assert.equal(classifyError(new SnapshotError('HTTP_ERROR'), 'SCREENSHOT_FAILED').code, 'HTTP_ERROR');
});
test('URL・Origin・IP・DNSの拒否理由を識別する', async () => {
  for (const [url, origins, resolver, code] of [
    ['file:///etc/passwd', [], async () => [], 'URL_BLOCKED'],
    ['https://outside.example', [origin], async () => [], 'ORIGIN_BLOCKED'],
    ['http://127.0.0.1', ['http://127.0.0.1'], async () => [], 'IP_BLOCKED'],
    [origin, [origin], async () => [{ address: '10.0.0.1' }], 'IP_BLOCKED'],
    [origin, [origin], async () => [], 'DNS_ERROR'],
    [origin, [origin], async () => { throw secretError; }, 'DNS_ERROR'],
  ]) await assert.rejects(validateDestination(url, origins, resolver), error => error.code === code && !error.message.includes('secret'));
});
test('ガードはURLを保持せず、Origin・redirect・通信・HTTP・WebSocketの失敗件数を返す', async () => {
  let handler, websocket;
  const diagnostics = await installNetworkGuard({ route: async (_, callback) => { handler = callback; }, routeWebSocket: async (_, callback) => { websocket = callback; } }, [origin]);
  let disposed = 0, aborted = 0;
  for (const [url, status, error, navigation] of [
    ['https://outside.example/?password=secret', 200, null, false],
    [secretUrl, 302, null, true], [secretUrl, 200, new Error('Timeout exceeded secret'), true], [secretUrl, 200, new Error('ENOTFOUND secret'), true],
    [secretUrl, 200, new Error('ERR_CERT_INVALID secret'), false], [secretUrl, 404, null, false],
  ]) {
    const frame = { page: () => ({ mainFrame: () => frame }) };
    await handler({
      request: () => ({ url: () => url, isNavigationRequest: () => navigation, frame: () => frame }),
      fetch: async () => { if (error) throw error; return { status: () => status, dispose: async () => { disposed++; } }; },
      abort: async () => { aborted++; throw secretError; }, fulfill: async () => {},
    });
  }
  websocket({ close: () => {} });
  assert.equal(aborted, 5);
  assert.equal(disposed, 2);
  assert.equal(diagnostics.blocked_resource_count, 7);
  assert.deepEqual(diagnostics.blocked_resource_reasons, { ORIGIN_BLOCKED: 1, REDIRECT_BLOCKED: 1, NAVIGATION_TIMEOUT: 1, DNS_ERROR: 1, TLS_ERROR: 1, HTTP_ERROR: 1, RESOURCE_BLOCKED: 1 });
  assert.equal(diagnostics.navigation_error_code, 'DNS_ERROR');
  assert.ok(!JSON.stringify(diagnostics).includes('secret'));
});
test('Runは各段階の失敗・成功・HTTP Status・duration・metadataを維持し、次のTargetとDeviceへ進む', async t => {
  const directory = await mkdtemp(join(tmpdir(), 'odvr-errors-'));
  t.after(() => rm(directory, { recursive: true, force: true }));
  const output = join(directory, 'run');
  const scenarios = [
    { http: 503 }, { navigation: new Error(`Timeout exceeded ${secretError.message}`) },
    { navigation: new Error(`ENOTFOUND ${secretError.message}`) }, { navigation: new Error(`ERR_CERT_INVALID ${secretError.message}`) },
    { crash: true, cleanup: true }, { brokenImage: true }, { imageTimeout: true }, { screenshot: true },
    {}, {}, {}, {},
  ];
  const launch = mock.method(chromium, 'launch', async () => {
    // ファイル保存だけを実際に失敗させる。
    await mkdir(output + '/target-5/desktop.png', { recursive: true });
    return browserFixture(scenarios);
  });
  t.after(() => launch.mock.restore());
  const manifest = { targets: Array.from({ length: 6 }, (_, i) => ({ id: i + 1, label: '対象', url: secretUrl })), devices: DEFAULT_DEVICES.slice(0, 2), settings: { ...DEFAULT_SETTINGS, concurrency: 1, lazy_load: false }, allowed_origins: [origin] };
  const report = await executeRun(manifest, output);
  assert.equal(report.total_snapshots, 12);
  assert.deepEqual(report.snapshots.slice(0, 9).map(s => s.error_code), ['HTTP_ERROR', 'NAVIGATION_TIMEOUT', 'DNS_ERROR', 'TLS_ERROR', 'PAGE_CRASH', 'IMAGE_LOAD_FAILED', 'IMAGE_LOAD_FAILED', 'SCREENSHOT_FAILED', 'FILE_SAVE_FAILED']);
  assert.equal(report.snapshots[0].http_status, 503);
  assert.equal(report.snapshots[5].http_status, 200);
  assert.equal(report.snapshots[4].metadata.cleanup_error_code, 'CONTEXT_CLOSE_FAILED');
  for (const result of report.snapshots) {
    assert.ok(result.duration_ms >= 0);
    assert.equal(result.metadata.network.blocked_resource_count, 0);
  }
  for (const result of report.snapshots.slice(9)) {
    assert.equal(result.status, 'CAPTURED');
    assert.equal(result.width, 2);
    assert.equal(result.height, 2);
  }
  const saved = await readFile(join(output, 'result.json'), 'utf8');
  assert.deepEqual(JSON.parse(saved), report);
  for (const secret of ['query-secret', 'fragment-secret', 'bearer-secret', 'basic-secret', 'shared-secret']) assert.ok(!saved.includes(secret), secret);
  assert.equal(manifest.targets[0].url, secretUrl);
});

test('ブロックされた副リソースは正常撮影にせず、主文書のredirectはHTTP Statusと拒否理由を残す', async t => {
  const directory = await mkdtemp(join(tmpdir(), 'odvr-blocked-'));
  t.after(() => rm(directory, { recursive: true, force: true }));
  const launch = mock.method(chromium, 'launch', async () => browserFixture([{ blocked: true }, { blocked: true, redirect: true }, {}]));
  t.after(() => launch.mock.restore());
  const report = await executeRun({ targets: [1, 2, 3].map(id => ({ id, label: '対象', url: secretUrl })), devices: [DEFAULT_DEVICES[0]], settings: { ...DEFAULT_SETTINGS, concurrency: 1, lazy_load: false }, allowed_origins: [origin] }, join(directory, 'run'));
  assert.equal(report.snapshots[0].error_code, 'RESOURCE_BLOCKED');
  assert.equal(report.snapshots[0].http_status, 200);
  assert.deepEqual(report.snapshots[0].metadata.network.blocked_resource_reasons, { ORIGIN_BLOCKED: 1 });
  assert.equal(report.snapshots[0].image_path, undefined);
  assert.equal(report.snapshots[1].error_code, 'REDIRECT_BLOCKED');
  assert.equal(report.snapshots[1].http_status, 302);
  assert.equal(report.snapshots[2].status, 'CAPTURED');
});

test('queryを保存せず同一URLのBaseline比較を維持し、queryの変更を見逃さない', async t => {
  const directory = await mkdtemp(join(tmpdir(), 'odvr-query-'));
  t.after(() => rm(directory, { recursive: true, force: true }));
  const launch = mock.method(chromium, 'launch', async () => browserFixture([{}]));
  t.after(() => launch.mock.restore());
  const manifest = { targets: [{ id: 1, label: '対象', url: secretUrl }], devices: [DEFAULT_DEVICES[0]], settings: { ...DEFAULT_SETTINGS, concurrency: 1, lazy_load: false }, allowed_origins: [origin] };
  const baseline = join(directory, 'baseline');
  await executeRun(manifest, baseline);
  const identical = await executeRun(manifest, join(directory, 'identical'), baseline);
  assert.equal(identical.snapshots[0].status, 'UNCHANGED');
  assert.equal(identical.status, 'COMPLETED');
  assert.equal(identical.run_error, null);
  assert.equal(identical.cleanup_error, null);
  assert.equal(identical.unexecuted_snapshots, 0);
  assert.equal(identical.snapshots[0].baseline_compatibility.state, 'COMPATIBLE');
  manifest.targets[0].url = `${origin}/?token=changed-secret`;
  const changed = await executeRun(manifest, join(directory, 'changed'), baseline);
  assert.equal(changed.snapshots[0].error_code, 'BASELINE_INCOMPATIBLE');
  assert.deepEqual(changed.snapshots[0].baseline_compatibility.reasons, ['target.url']);
  for (const report of [identical, changed]) assert.ok(!JSON.stringify(report).includes('secret'));
});

test('正常撮影と単一対象失敗の結果はBrowser終了失敗後も保持される', async t => {
  const directory = await mkdtemp(join(tmpdir(), 'odvr-close-'));
  t.after(() => rm(directory, { recursive: true, force: true }));
  const launch = mock.method(chromium, 'launch', async () => ({ ...browserFixture([{}, { http: 503 }]), close: async () => { throw secretError; } }));
  t.after(() => launch.mock.restore());
  const output = join(directory, 'run');
  const report = await executeRun({ targets: [1, 2].map(id => ({ id, label: '対象', url: secretUrl })), devices: [DEFAULT_DEVICES[0]], settings: { ...DEFAULT_SETTINGS, concurrency: 1, lazy_load: false }, allowed_origins: [origin] }, output);
  assert.equal(report.run_error.error_code, 'BROWSER_CLOSE_FAILED');
  assert.equal(report.total_snapshots, 2);
  assert.equal(report.error_snapshots, 1);
  assert.equal(report.unexecuted_snapshots, 0);
  assert.equal(report.snapshots[0].status, 'CAPTURED');
  assert.equal(report.snapshots[1].error_code, 'HTTP_ERROR');
  assert.deepEqual(JSON.parse(await readFile(join(output, 'result.json'), 'utf8')), report);
});

test('CLIの失敗ログへURL・資格情報・機密queryを出さない', () => {
  const { status, stderr } = spawnSync(process.execPath, ['apps/runner/dist/index.js', '/missing/Bearer-bearer-secret-Shared-Secret-shared-secret?token=query-secret', '/unused'], { encoding: 'utf8' });
  assert.equal(status, 1);
  assert.match(stderr, /SNAPSHOT_FAILED/);
  for (const secret of ['bearer-secret', 'shared-secret', 'query-secret']) assert.ok(!stderr.includes(secret));
});
