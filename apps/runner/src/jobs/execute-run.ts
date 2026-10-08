import { mkdir, rename, rm, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { join, resolve } from 'node:path';
import { chromium, type Browser } from 'playwright';
import type { NetworkDiagnostics, PrototypeManifest, SnapshotErrorCode } from '@odvr/shared';
import { SnapshotError, classifyError, safeUrl, urlFingerprint } from '../errors.js';
import { createContext, captureAuth, BROWSER_LAUNCH_OPTIONS } from '../browser/context-factory.js';
import { capturePage } from '../browser/screenshot.js';
import { installNetworkGuard } from '../security/network-guard.js';
import { DestinationPolicy } from '../security/destination-policy.js';
import { PinnedHttpClient } from '../security/pinned-http-client.js';
import { compareImages } from '../visual/compare.js';
import { decodeImage } from '../visual/normalize-image.js';
import { inspectBaseline, loadBaseline } from './baseline.js';
type RunErrorCode = 'BROWSER_LAUNCH_FAILED' | 'BROWSER_CLOSE_FAILED' | 'RUN_FAILED';
const runMessages: Record<RunErrorCode, string> = {
  BROWSER_LAUNCH_FAILED: 'Browserの起動に失敗しました',
  BROWSER_CLOSE_FAILED: 'Browserの終了に失敗しました',
  RUN_FAILED: 'Runの処理に失敗しました',
};
const runFailure = (code: RunErrorCode) => ({ error_code: code, error_message: runMessages[code] });
export class RunResultSaveError extends Error {
  constructor(public readonly run_error: ReturnType<typeof runFailure> | null, public readonly cleanup_error: ReturnType<typeof runFailure> | null) {
    super('Runの結果を保存できませんでした');
  }
}
export async function executeRun(manifest: PrototypeManifest, output: string, baseline?: string) {
  if (baseline && resolve(output) === resolve(baseline)) throw new Error('出力先とBaselineは別のディレクトリにしてください');
  // 既存のRunを上書きしない。
  await mkdir(output, { recursive: false, mode: 0o700 });
  const started = new Date().toISOString();
  const queue = manifest.targets.flatMap(target => manifest.devices.map(device => ({ target, device })));
  const planned = queue.length;
  const results: Record<string, unknown>[] = [];
  let browser: Browser | undefined;
  let transport: PinnedHttpClient | undefined;
  let versions: { runner_version: string | null; playwright_version: string | null; chromium_version: string | null } = { runner_version: null, playwright_version: null, chromium_version: null };
  let runError: ReturnType<typeof runFailure> | null = null;
  let cleanupError: ReturnType<typeof runFailure> | null = null;
  let stage: RunErrorCode = 'RUN_FAILED';
  try {
    const require = createRequire(import.meta.url);
    versions.runner_version = require('../../package.json').version as string;
    versions.playwright_version = require('playwright/package.json').version as string;
    stage = 'BROWSER_LAUNCH_FAILED';
    browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
    transport = new PinnedHttpClient({policy:new DestinationPolicy({captureOrigins:manifest.allowed_origins})});
    stage = 'RUN_FAILED';
    const captureVersions = { runner_version: versions.runner_version, playwright_version: versions.playwright_version, chromium_version: browser.version() };
    versions = captureVersions;
    const auth = captureAuth(process.env.ODVR_HTTP_AUTH_ORIGIN ?? new URL(manifest.targets[0].url).origin);
    if (auth?.kind === 'basic') new DestinationPolicy({captureOrigins:manifest.allowed_origins}).validate(auth.origin,'capture');
    const referenceRun = baseline ? await loadBaseline(baseline) : undefined;
    const workers = await Promise.allSettled(Array.from({ length: manifest.settings.concurrency }, async () => {
      for (;;) {
        const task = queue.shift();
        if (!task) return;
        const { target, device } = task;
        const begin = Date.now();
        const result: Record<string, unknown> = { target_id: target.id, device_id: device.id, url: safeUrl(target.url), http_status: null, metadata: { url_fingerprint: urlFingerprint(target.url) } };
        let context;
        let diagnostics: NetworkDiagnostics | undefined;
        let stage: SnapshotErrorCode = 'SNAPSHOT_FAILED';
        let crashed = false;
        try {
          new DestinationPolicy({captureOrigins:manifest.allowed_origins}).validate(target.url,'capture');
          stage = 'BROWSER_ERROR';
          context = await createContext(browser!, device);
          diagnostics = await installNetworkGuard(context, manifest.allowed_origins, {client:transport!,targetKey:`${target.id}:${device.id}`,auth});
          const page = await context.newPage();
          page.on('crash', () => { crashed = true; });
          stage = 'NAVIGATION_TIMEOUT';
          const response = await page.goto(target.url, { waitUntil: 'domcontentloaded', timeout: manifest.settings.navigation_timeout_ms });
          result.http_status = response?.status() ?? null;
          if (!response || response.status() >= 400) throw new SnapshotError('HTTP_ERROR');
          stage = 'SCREENSHOT_FAILED';
          const image = await capturePage(page, manifest.settings);
          const decoded = decodeImage(image);
          if (crashed) throw new SnapshotError('PAGE_CRASH');
          if (diagnostics.blocked_resource_count) throw new SnapshotError('RESOURCE_BLOCKED');
          stage = 'FILE_SAVE_FAILED';
          const directory = join(output, `target-${target.id}`);
          await mkdir(directory, { recursive: true });
          await writeFile(join(directory, `${device.slug}.png`), image);
          Object.assign(result, { status: 'CAPTURED', width: decoded.width, height: decoded.height, image_path: `target-${target.id}/${device.slug}.png` });
          stage = 'SNAPSHOT_FAILED';
          if (baseline) {
            const reference = await inspectBaseline(referenceRun!, baseline, manifest, target, device, captureVersions);
            result.baseline_compatibility = reference.compatibility;
            if (reference.image) {
              const { diff_image, ...comparison } = compareImages(reference.image, image, manifest.settings);
              stage = 'FILE_SAVE_FAILED';
              await writeFile(join(directory, `${device.slug}-diff.png`), diff_image);
              Object.assign(result, comparison, { diff_path: `target-${target.id}/${device.slug}-diff.png` });
            } else if (['SNAPSHOT_MISSING', 'IMAGE_MISSING'].includes(reference.compatibility.state)) {
              result.status = 'NO_BASELINE';
            } else {
              Object.assign(result, { status: 'ERROR', error_code: `BASELINE_${reference.compatibility.state}`, error_message: `Baselineを比較できません: ${reference.compatibility.reasons.join(', ')}` });
            }
          }
        } catch (error) {
          const failure = crashed ? new SnapshotError('PAGE_CRASH') : diagnostics?.navigation_error_code ? new SnapshotError(diagnostics.navigation_error_code) : classifyError(error, stage);
          Object.assign(result, { status: 'ERROR', error_code: failure.code, error_message: failure.message });
        } finally {
          try { await context?.close(); } catch {
            if (result.status !== 'ERROR') Object.assign(result, { status: 'ERROR', error_code: 'CONTEXT_CLOSE_FAILED', error_message: new SnapshotError('CONTEXT_CLOSE_FAILED').message });
            result.metadata = { ...result.metadata as object, cleanup_error_code: 'CONTEXT_CLOSE_FAILED' };
          }
          if (diagnostics) {
            result.metadata = { ...result.metadata as object, network: diagnostics };
            result.http_status ??= diagnostics.navigation_http_status ?? null;
            if (result.status !== 'ERROR' && diagnostics.blocked_resource_count) {
              const failure = new SnapshotError('RESOURCE_BLOCKED');
              Object.assign(result, { status: 'ERROR', error_code: failure.code, error_message: failure.message });
            }
          }
          result.duration_ms = Date.now() - begin;
          results.push(result);
        }
      }
    }));
    const failed = workers.find(worker => worker.status === 'rejected');
    if (failed?.status === 'rejected') throw failed.reason;
  } catch {
    runError = runFailure(stage);
  } finally {
    transport?.close();
    try { await browser?.close(); }
    catch {
      cleanupError = runFailure('BROWSER_CLOSE_FAILED');
      runError ??= cleanupError;
    }
  }
  results.sort((a, b) => Number(a.target_id) - Number(b.target_id) || Number(a.device_id) - Number(b.device_id));
  const report = { ...versions, configuration: { ...manifest, targets: manifest.targets.map(target => ({ ...target, url: safeUrl(target.url) })), allowed_origins: manifest.allowed_origins }, started_at: started, completed_at: new Date().toISOString(), status: runError || results.some(result => result.status === 'ERROR') || results.length !== planned ? 'ERROR' : 'COMPLETED', run_error: runError, cleanup_error: cleanupError, planned_snapshots: planned, total_snapshots: results.length, unexecuted_snapshots: planned - results.length, error_snapshots: results.filter(result => result.status === 'ERROR').length, snapshots: results };
  const temporary = join(output, 'result.json.tmp');
  try {
    await writeFile(temporary, JSON.stringify(report, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
    await rename(temporary, join(output, 'result.json'));
  } catch {
    await rm(temporary, { force: true }).catch(() => {});
    // 元のRunエラーを置き換えず、安全な保存失敗として呼び出し元へ伝える。
    throw new RunResultSaveError(runError, cleanupError);
  }
  return report;
}
