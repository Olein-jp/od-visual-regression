import { spawnSync } from 'node:child_process';
import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { DEFAULT_DEVICES, DEFAULT_SETTINGS } from '@odvr/shared';
import { executeRun, RunResultSaveError } from '../dist/jobs/execute-run.js';
const secret = new Error('https://user:password@example.com/?token=secret Authorization: Bearer secret');
const manifest = { targets: [1, 2].map(id => ({ id, label: '対象', url: 'http://127.0.0.1/?token=secret' })), devices: [DEFAULT_DEVICES[0]], settings: DEFAULT_SETTINGS, allowed_origins: ['http://127.0.0.1'] };
async function fixture(t, launch) {
  const directory = await mkdtemp(join(tmpdir(), 'odvr-lifecycle-'));
  t.after(() => rm(directory, { recursive: true, force: true }));
  const mocked = mock.method(chromium, 'launch', launch);
  t.after(() => mocked.mock.restore());
  return { directory, output: join(directory, 'run'), mocked };
}
async function saved(output, report) {
  const raw = await readFile(join(output, 'result.json'), 'utf8');
  assert.deepEqual(JSON.parse(raw), report);
  for (const value of ['secret', 'password', 'Authorization', 'Bearer']) assert.ok(!raw.includes(value));
  assert.ok(!(await readdir(output)).includes('result.json.tmp'));
}
test('Browser起動失敗を保存し、全対象を未実行として数える', async t => {
  const { output } = await fixture(t, async () => { throw secret; });
  const report = await executeRun(manifest, output);
  assert.equal(report.status, 'ERROR');
  assert.equal(report.run_error.error_code, 'BROWSER_LAUNCH_FAILED');
  assert.equal(report.cleanup_error, null);
  assert.equal(report.chromium_version, null);
  assert.equal(report.planned_snapshots, 2);
  assert.equal(report.total_snapshots, 0);
  assert.equal(report.unexecuted_snapshots, 2);
  assert.equal(report.error_snapshots, 0);
  assert.deepEqual(report.snapshots, []);
  assert.ok(report.started_at <= report.completed_at);
  await saved(output, report);
});
test('Browser終了失敗でも対象ごとの失敗結果を保存する', async t => {
  let closed = 0;
  const { output } = await fixture(t, async () => ({ version: () => 'test', close: async () => { closed++; throw secret; } }));
  const report = await executeRun(manifest, output);
  assert.equal(closed, 1);
  assert.equal(report.status, 'ERROR');
  assert.equal(report.run_error.error_code, 'BROWSER_CLOSE_FAILED');
  assert.equal(report.cleanup_error.error_code, 'BROWSER_CLOSE_FAILED');
  assert.equal(report.total_snapshots, 2);
  assert.equal(report.unexecuted_snapshots, 0);
  assert.equal(report.error_snapshots, 2);
  assert.ok(report.snapshots.every(snapshot => snapshot.error_code === 'IP_BLOCKED'));
  await saved(output, report);
});
test('実行準備の主エラーをBrowser終了の二次エラーで上書きしない', async t => {
  let closed = 0;
  const { output } = await fixture(t, async () => ({ version: () => { throw secret; }, close: async () => { closed++; throw secret; } }));
  const report = await executeRun(manifest, output);
  assert.equal(closed, 1);
  assert.equal(report.run_error.error_code, 'RUN_FAILED');
  assert.equal(report.cleanup_error.error_code, 'BROWSER_CLOSE_FAILED');
  assert.equal(report.total_snapshots, 0);
  assert.equal(report.unexecuted_snapshots, 2);
  await saved(output, report);
});
test('既存の出力先とBaselineを保護しBrowserを起動しない', async t => {
  const { output, mocked } = await fixture(t, async () => { throw secret; });
  await mkdir(output);
  await writeFile(join(output, 'result.json'), '既存の結果');
  await assert.rejects(executeRun(manifest, output), { code: 'EEXIST' });
  await assert.rejects(executeRun(manifest, output, output), /別のディレクトリ/);
  assert.equal(mocked.mock.callCount(), 0);
  assert.equal(await readFile(join(output, 'result.json'), 'utf8'), '既存の結果');
});
test('結果の置換失敗では部分JSONを残さず、元のRunエラーを保持する', async t => {
  const { output } = await fixture(t, async () => {
    await mkdir(join(output, 'result.json'));
    await writeFile(join(output, 'result.json', 'existing'), '既存');
    throw secret;
  });
  await assert.rejects(executeRun(manifest, output), error => {
    assert.ok(error instanceof RunResultSaveError);
    assert.equal(error.run_error.error_code, 'BROWSER_LAUNCH_FAILED');
    assert.ok(!error.message.includes('secret'));
    return true;
  });
  assert.deepEqual(await readdir(output), ['result.json']);
  assert.equal(await readFile(join(output, 'result.json', 'existing'), 'utf8'), '既存');
});
test('CLIはBrowser起動・終了失敗で結果を保存し終了コード1を返す', async t => {
  const directory = await mkdtemp(join(tmpdir(), 'odvr-lifecycle-cli-'));
  t.after(() => rm(directory, { recursive: true, force: true }));
  const manifestPath = join(directory, 'manifest.json');
  // CLIのManifest検証を通し、Browserのライフサイクルまで到達させる。
  await writeFile(manifestPath, JSON.stringify({ ...manifest, targets: manifest.targets.map(target => ({ ...target, url: 'https://8.8.8.8/?token=secret' })), allowed_origins: ['https://8.8.8.8'] }));
  for (const stage of ['launch', 'close']) {
    const bootstrap = join(directory, `${stage}.mjs`);
    await writeFile(bootstrap, `import { chromium } from ${JSON.stringify(import.meta.resolve('playwright'))};\nchromium.launch = async () => ${stage === 'launch' ? '{ throw new Error("Bearer secret"); }' : '({ version: () => "test", close: async () => { throw new Error("Bearer secret"); } })'};\n`);
    const output = join(directory, stage);
    const { status, stdout, stderr } = spawnSync(process.execPath, ['--import', pathToFileURL(bootstrap).href, 'apps/runner/dist/index.js', manifestPath, output], { encoding: 'utf8' });
    assert.equal(status, 1, stderr);
    assert.match(stderr, new RegExp(`BROWSER_${stage.toUpperCase()}_FAILED`));
    assert.ok(!stderr.includes('secret'));
    assert.match(stdout, new RegExp(`未実行${stage === 'launch' ? 2 : 0}件`));
    assert.equal(JSON.parse(await readFile(join(output, 'result.json'), 'utf8')).status, 'ERROR');
  }
});
