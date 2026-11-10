import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { selectChecks, changedPaths } from '../scripts/ci-paths.mjs';

test('docsだけの変更は製品の検証・Image buildを起動しない', () => {
  assert.deepEqual(selectChecks(['docs/cloud-staging.md', 'README.md']),
    { runner: false, dispatcher: false, wordpress: false, images: false, plugin: false });
});
test('Runner・Dispatcher・WordPressの変更に対応する検証を選択する', () => {
  assert.deepEqual(selectChecks(['apps/runner/src/job.ts']),
    { runner: true, dispatcher: true, wordpress: false, images: true, plugin: false });
  assert.deepEqual(selectChecks(['apps/dispatcher/src/engine.ts']),
    { runner: false, dispatcher: true, wordpress: false, images: true, plugin: false });
  assert.deepEqual(selectChecks(['wordpress/od-visual-regression/includes/example.php']),
    { runner: false, dispatcher: false, wordpress: true, images: true, plugin: true });
});
test('共通契約・依存・infra/workflowと未知ファイルは関連検証を省略しない', () => {
  for (const path of ['packages/shared/src/index.ts', 'package-lock.json', 'infra/wordpress/gateway/index.php',
    '.github/workflows/ci.yml', 'scripts/validate-schemas.mjs', 'unexpected-config.json'])
    assert.ok(Object.values(selectChecks([path])).every(Boolean));
});
test('実git差分でrenameの移動元・先、削除を検証対象に残す', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'odvr-ci-paths-'));
  const git = (...args) => execFileSync('git', args, { cwd: directory, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  try {
    git('init'); git('config', 'user.name', 'ODVR fixture'); git('config', 'user.email', 'fixture@invalid.test');
    await mkdir(join(directory, 'apps/runner'), { recursive: true });
    await writeFile(join(directory, 'apps/runner/removed.ts'), 'fixture\n');
    git('add', '.'); git('commit', '-m', 'fixture'); const base = git('rev-parse', 'HEAD');
    await mkdir(join(directory, 'docs')); git('mv', 'apps/runner/removed.ts', 'docs/moved.md');
    git('commit', '-m', 'move'); const head = git('rev-parse', 'HEAD');
    const paths = changedPaths('pull_request', { pull_request: { base: { sha: base }, head: { sha: head } } }, directory);
    assert.deepEqual(paths.sort(), ['apps/runner/removed.ts', 'docs/moved.md']);
    assert.equal(selectChecks(paths).runner, true);
    assert.deepEqual(changedPaths('push', { before: base, after: head }, directory).sort(), paths);
    assert.equal(changedPaths('push', { before: '0'.repeat(40), after: head }, directory), null);
    assert.equal(changedPaths('push', { before: '1'.repeat(40), after: head }, directory), null);
    assert.equal(changedPaths('workflow_dispatch', {}, directory), null);
    assert.equal(changedPaths('pull_request', { pull_request: { base: { sha: '--bad' }, head: { sha: head } } }, directory), null);
  } finally { await rm(directory, { recursive: true, force: true }); }
});
