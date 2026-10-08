import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { PNG } from 'pngjs';
import { DEFAULT_SETTINGS, DEFAULT_DEVICES } from '@odvr/shared';
import { loadBaseline, inspectBaseline } from '../dist/jobs/baseline.js';
import { compareImages } from '../dist/visual/compare.js';
const versions = { runner_version: '0.1.0', playwright_version: '1.50.0', chromium_version: '130.0' };
const manifest = { targets: [{ id: 1, url: 'https://example.com/', label: 'ホーム' }], devices: [DEFAULT_DEVICES[0]], settings: DEFAULT_SETTINGS, allowed_origins: ['https://example.com'] };
function image(height = 2, black = false) {
  const png = new PNG({ width: 2, height });
  png.data.fill(black ? 0 : 255);
  for (let offset = 3; offset < png.data.length; offset += 4) png.data[offset] = 255;
  return PNG.sync.write(png);
}
async function fixture(t) {
  const directory = await mkdtemp(join(tmpdir(), 'odvr-baseline-'));
  t.after(() => rm(directory, { recursive: true, force: true }));
  await mkdir(join(directory, 'target-1'));
  await writeFile(join(directory, 'target-1/desktop.png'), image());
  const report = structuredClone({ ...versions, configuration: manifest, snapshots: [{ target_id: 1, device_id: 1, url: manifest.targets[0].url, status: 'CAPTURED', width: 2, height: 2, image_path: 'target-1/desktop.png' }] });
  const save = () => writeFile(join(directory, 'result.json'), JSON.stringify(report));
  await save();
  const inspect = async (current = manifest, currentVersions = versions) => inspectBaseline(await loadBaseline(directory), directory, current, current.targets[0], current.devices[0], currentVersions);
  return { directory, report, save, inspect };
}
test('同条件のBaselineを比較し、画像寸法の変更と従来の差分判定を維持する', async t => {
  const f = await fixture(t);
  const reference = await f.inspect();
  assert.deepEqual(reference.compatibility, { state: 'COMPATIBLE', reasons: [] });
  assert.equal(compareImages(reference.image, image(), DEFAULT_SETTINGS).diff_ratio, 0);
  const resized = compareImages(reference.image, image(3), DEFAULT_SETTINGS);
  assert.equal(resized.dimension_changed, true);
  assert.equal(resized.diff_pixels, 2);
  assert.equal(resized.diff_ratio, 2 / 6);
  assert.equal(resized.status, 'CHANGED');
});
test('対象ID・URL、Deviceの撮影プロファイル、撮影設定、各バージョンを照合する', async t => {
  const changes = [
    ['target_id', m => { m.targets[0].id = 2; }],
    ['target.url', m => { m.targets[0].url += 'other'; }],
    ['device_id', m => { m.devices[0].id = 2; }],
    ...['viewport_width', 'viewport_height', 'device_scale_factor'].map(key => [`device.${key}`, m => { m.devices[0][key] += 1; }]),
    ...['is_mobile', 'has_touch'].map(key => [`device.${key}`, m => { m.devices[0][key] = !m.devices[0][key]; }]),
    ['device.user_agent', m => { m.devices[0].user_agent = 'custom'; }],
    ...['navigation_timeout_ms', 'image_timeout_ms'].map(key => [`settings.${key}`, m => { m.settings[key] += 100; }]),
    ['settings.lazy_load', m => { m.settings.lazy_load = false; }],
    ['settings.ignore_selectors', m => { m.settings.ignore_selectors = ['.mask']; }],
    ['allowed_origins', m => { m.allowed_origins.push('https://cdn.example.com'); }],
  ];
  const f = await fixture(t);
  for (const [reason, change] of changes) {
    const current = structuredClone(manifest);
    change(current);
    const result = await f.inspect(current);
    assert.equal(result.compatibility.state, 'INCOMPATIBLE', reason);
    assert.deepEqual(result.compatibility.reasons, [reason]);
    assert.equal(result.image, undefined);
  }
  for (const key of Object.keys(versions)) {
    const result = await f.inspect(manifest, { ...versions, [key]: 'different' });
    assert.deepEqual(result.compatibility, { state: 'INCOMPATIBLE', reasons: [key] });
    assert.equal(result.image, undefined);
  }
});
test('判定閾値・並列数・表示名は撮影条件から除き、現在の閾値で判定する', async t => {
  const f = await fixture(t);
  const current = structuredClone(manifest);
  Object.assign(current.settings, { pixel_threshold: 0.5, review_threshold: 0.5, changed_threshold: 0.9, concurrency: 1 });
  current.targets[0].label = '変更後';
  current.devices[0].name = '表示名変更';
  current.devices[0].slug = 'renamed';
  const reference = await f.inspect(current);
  assert.equal(reference.compatibility.state, 'COMPATIBLE');
  const compared = compareImages(reference.image, image(3), current.settings);
  assert.equal(compared.status, 'UNCHANGED');
  assert.equal(compareImages(reference.image, image(2, true), current.settings).diff_ratio, 1);
});
test('マスクと許可Originの順序・重複を正規化する', async t => {
  const f = await fixture(t);
  f.report.configuration.settings.ignore_selectors = ['.a', '.b'];
  f.report.configuration.allowed_origins.push('https://cdn.example.com');
  await f.save();
  const current = structuredClone(f.report.configuration);
  current.settings.ignore_selectors = ['.b', '.a', '.a'];
  current.allowed_origins.reverse();
  assert.equal((await f.inspect(current)).compatibility.state, 'COMPATIBLE');
});
test('結果JSONの欠損・構文破損・不正構造・旧Baselineの情報不足を区別する', async t => {
  const f = await fixture(t);
  await rm(join(f.directory, 'result.json'));
  assert.equal((await f.inspect()).compatibility.state, 'RESULT_MISSING');
  for (const raw of ['broken', 'null', '[]']) {
    await writeFile(join(f.directory, 'result.json'), raw);
    assert.equal((await f.inspect()).compatibility.state, 'RESULT_INVALID');
  }
  for (const key of ['configuration', ...Object.keys(versions), 'snapshots']) {
    const report = structuredClone(f.report);
    delete report[key];
    await writeFile(join(f.directory, 'result.json'), JSON.stringify(report));
    const result = await f.inspect();
    assert.deepEqual(result.compatibility, { state: 'METADATA_MISSING', reasons: [key] });
    assert.equal(result.image, undefined);
  }
  for (const patch of [{ runner_version: '' }, { playwright_version: 123 }, { chromium_version: null }, { snapshots: {} }, { snapshots: [{ target_id: '1', device_id: 1, url: 'https://example.com/', status: 'CAPTURED' }] }]) {
    await writeFile(join(f.directory, 'result.json'), JSON.stringify({ ...f.report, ...patch }));
    assert.equal((await f.inspect()).compatibility.state, 'RESULT_INVALID');
  }
  f.report.configuration.settings.lazy_load = 'invalid';
  await f.save();
  assert.equal((await f.inspect()).compatibility.state, 'RESULT_INVALID');
});
test('JSON・画像の欠損以外の読み取り失敗を記録する', async t => {
  const f = await fixture(t);
  await rm(join(f.directory, 'target-1/desktop.png'));
  await mkdir(join(f.directory, 'target-1/desktop.png'));
  assert.equal((await f.inspect()).compatibility.state, 'READ_FAILED');
  await rm(join(f.directory, 'result.json'));
  await mkdir(join(f.directory, 'result.json'));
  assert.equal((await f.inspect()).compatibility.state, 'READ_FAILED');
});
test('Snapshot・画像の欠損、PNG破損、JSONと画像の矛盾を区別する', async t => {
  const f = await fixture(t);
  const snapshot = structuredClone(f.report.snapshots[0]);
  f.report.snapshots = [];
  await f.save();
  assert.equal((await f.inspect()).compatibility.state, 'SNAPSHOT_MISSING');
  f.report.snapshots = [snapshot];
  await f.save();
  await rm(join(f.directory, 'target-1/desktop.png'));
  assert.equal((await f.inspect()).compatibility.state, 'IMAGE_MISSING');
  await writeFile(join(f.directory, 'target-1/desktop.png'), 'broken');
  assert.equal((await f.inspect()).compatibility.state, 'IMAGE_INVALID');
  await writeFile(join(f.directory, 'target-1/desktop.png'), image());
  for (const patch of [{ url: 'https://example.com/other' }, { image_path: '../../other.png' }, { width: 3 }, { status: 'ERROR' }]) {
    f.report.snapshots = [{ ...snapshot, ...patch }];
    await f.save();
    assert.equal((await f.inspect()).compatibility.state, 'RESULT_INVALID');
  }
  f.report.snapshots = [snapshot, snapshot];
  await f.save();
  assert.equal((await f.inspect()).compatibility.state, 'RESULT_INVALID');
});
