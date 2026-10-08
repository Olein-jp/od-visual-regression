import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { PNG } from 'pngjs';
import { DEFAULT_DEVICES, DEFAULT_SETTINGS } from '@odvr/shared';
import { createContext } from '../dist/browser/context-factory.js';
import { capturePage } from '../dist/browser/screenshot.js';
import { compareImages } from '../dist/visual/compare.js';
test('Chromiumで全ページ撮影・マスク・変更比較を行う', async () => {
  const browser = await chromium.launch();
  try {
    const context = await createContext(browser, DEFAULT_DEVICES[0]);
    const page = await context.newPage();
    await page.setContent('<style>body{margin:0}.page{height:1500px;background:white}.dynamic{width:200px;height:100px}</style><div class="page"><div class="dynamic" data-odvr-ignore>初期表示</div></div>');
    const baseline = await capturePage(page, DEFAULT_SETTINGS);
    const dimensions = PNG.sync.read(baseline);
    assert.equal(dimensions.width, 1440);
    assert.equal(dimensions.height, 1500);
    await page.locator('.dynamic').evaluate(node => node.textContent = '更新された時刻');
    const masked = await capturePage(page, DEFAULT_SETTINGS);
    assert.equal(compareImages(baseline, masked, DEFAULT_SETTINGS).diff_pixels, 0);
    await page.locator('.page').evaluate(node => node.style.background = 'black');
    const changed = await capturePage(page, DEFAULT_SETTINGS);
    assert.equal(compareImages(baseline, changed, DEFAULT_SETTINGS).status, 'CHANGED');
    await context.close();
  } finally { await browser.close(); }
});

test('Browserのリクエストガードが内部ホストへの遷移を拒否する', async () => {
  const { installNetworkGuard } = await import('../dist/security/network-guard.js');
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    const diagnostics = await installNetworkGuard(context, ['http://127.0.0.1:12345']);
    const page = await context.newPage();
    await assert.rejects(page.goto('http://127.0.0.1:12345', { timeout: 2000 }));
    assert.equal(diagnostics.navigation_error_code, 'IP_BLOCKED');
    assert.equal(diagnostics.blocked_resource_reasons.IP_BLOCKED, 1);
    await context.close();
  } finally { await browser.close(); }
});

test('Runは対象の失敗後も継続し、履歴の上書きを拒否する', async () => {
  const { mkdtemp, readFile, rm } = await import('node:fs/promises');
  const { tmpdir } = await import('node:os');
  const { join } = await import('node:path');
  const { executeRun } = await import('../dist/jobs/execute-run.js');
  const directory = await mkdtemp(join(tmpdir(), 'odvr-test-'));
  const output = join(directory, 'run');
  const manifest = { targets: [{ id: 1, label: '拒否対象', url: 'http://127.0.0.1/' }, { id: 2, label: '次の対象', url: 'http://127.0.0.1/' }], devices: [DEFAULT_DEVICES[0]], settings: DEFAULT_SETTINGS, allowed_origins: ['http://127.0.0.1'] };
  try {
    const report = await executeRun(manifest, output);
    assert.equal(report.total_snapshots, 2);
    assert.equal(report.error_snapshots, 2);
    assert.ok(report.snapshots.every(snapshot => snapshot.error_code === 'IP_BLOCKED' && snapshot.http_status === null && snapshot.duration_ms >= 0));
    assert.equal(JSON.parse(await readFile(join(output, 'result.json'))).snapshots.length, 2);
    await assert.rejects(executeRun(manifest, output));
    await assert.rejects(executeRun(manifest, output, output), /別のディレクトリ/);
  } finally { await rm(directory, { recursive: true, force: true }); }
});

test('Chromiumの画像読込失敗をIMAGE_LOAD_FAILEDとして返す', async () => {
  const browser = await chromium.launch();
  try {
    const context = await createContext(browser, DEFAULT_DEVICES[0]);
    const page = await context.newPage();
    await page.setContent('<img src="data:image/png;base64,Ym9ya2Vu">');
    await assert.rejects(capturePage(page, DEFAULT_SETTINGS), error => error.code === 'IMAGE_LOAD_FAILED');
    await context.close();
  } finally { await browser.close(); }
});
