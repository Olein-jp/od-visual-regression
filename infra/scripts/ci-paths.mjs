import { execFileSync } from 'node:child_process';
import { readFile, appendFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const all = () => ({ runner: true, dispatcher: true, wordpress: true, images: true, plugin: true });
export function selectChecks(paths) {
  const selected = { runner: false, dispatcher: false, wordpress: false, images: false, plugin: false };
  for (const path of paths) {
    if (path.startsWith('docs/') || /^(?:README[^/]*|LICENSE[^/]*|AGENTS\.md)$/.test(path)) continue;
    if (path.startsWith('apps/runner/')) { selected.runner = true; selected.dispatcher = true; selected.images = true; continue; }
    if (path.startsWith('apps/dispatcher/')) { selected.dispatcher = true; selected.images = true; continue; }
    if (path.startsWith('wordpress/')) { selected.wordpress = true; selected.plugin = true; selected.images = true; continue; }
    if (/^(?:composer\.(?:json|lock)|phpcs\.xml[^/]*|\.phpcs[^/]*|\.wp-env[^/]*)$/.test(path)) {
      selected.wordpress = true; selected.plugin = true; selected.images = true; continue;
    }
    // 共通契約・Image/infra/workflow・未知パスは関連漏れを避けて全検証する。
    return all();
  }
  return selected;
}

export function changedPaths(eventName, event, cwd = process.cwd()) {
  const base = eventName === 'pull_request' ? event.pull_request?.base?.sha : event.before;
  const head = eventName === 'pull_request' ? event.pull_request?.head?.sha : event.after;
  const sha = value => typeof value === 'string' && /^[a-f0-9]{40,64}$/.test(value) && !/^0+$/.test(value);
  if (!['push', 'pull_request'].includes(eventName) || !sha(base) || !sha(head)) return null;
  try {
    // 削除も判定に含める。renameを分解して移動元・先の両方を検証する。
    const output = execFileSync('git', ['diff', '--name-only', '--no-renames', '-z', base, head, '--'],
      { cwd, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] });
    return output.split('\0').filter(Boolean);
  } catch { return null; }
}

export async function main(environment = process.env) {
  let paths = null;
  try { paths = changedPaths(environment.GITHUB_EVENT_NAME, JSON.parse(await readFile(environment.GITHUB_EVENT_PATH, 'utf8'))); }
  catch { /* 不明な差分は全検証にする。 */ }
  const checks = paths === null ? all() : selectChecks(paths);
  const output = Object.entries(checks).map(([name, value]) => `${name}=${value}\n`).join('');
  if (environment.GITHUB_OUTPUT) await appendFile(environment.GITHUB_OUTPUT, output);
  process.stdout.write(output);
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) await main();
