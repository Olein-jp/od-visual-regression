import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { join, resolve } from 'node:path';
import { chromium } from 'playwright';
import type { PrototypeManifest } from '@odvr/shared';
import { createContext } from '../browser/context-factory.js';
import { capturePage } from '../browser/screenshot.js';
import { installNetworkGuard } from '../security/network-guard.js';
import { validateDestination } from '../security/url-validator.js';
import { compareImages } from '../visual/compare.js';
import { decodeImage } from '../visual/normalize-image.js';
export async function executeRun(manifest: PrototypeManifest, output: string, baseline?: string) {
  if (baseline && resolve(output) === resolve(baseline)) throw new Error('出力先とBaselineは別のディレクトリにしてください');
  // 既存のRunを上書きしない。
  await mkdir(output, { recursive: false, mode: 0o700 });
  const browser = await chromium.launch();
  const started = new Date().toISOString();
  const queue = manifest.targets.flatMap(target => manifest.devices.map(device => ({ target, device })));
  const results: Record<string, unknown>[] = [];
  try {
    await Promise.all(Array.from({ length: manifest.settings.concurrency }, async () => {
      for (;;) {
        const task = queue.shift();
        if (!task) return;
        const { target, device } = task;
        const begin = Date.now();
        const result: Record<string, unknown> = { target_id: target.id, device_id: device.id, url: target.url };
        let context;
        try {
          await validateDestination(target.url, manifest.allowed_origins);
          context = await createContext(browser, device, new URL(target.url).origin);
          await installNetworkGuard(context, manifest.allowed_origins);
          const page = await context.newPage();
          const response = await page.goto(target.url, { waitUntil: 'domcontentloaded', timeout: manifest.settings.navigation_timeout_ms });
          result.http_status = response?.status() ?? null;
          if (!response || response.status() >= 400) throw new Error('HTTP応答に失敗しました');
          const image = await capturePage(page, manifest.settings);
          const decoded = decodeImage(image);
          const directory = join(output, `target-${target.id}`);
          await mkdir(directory, { recursive: true });
          await writeFile(join(directory, `${device.slug}.png`), image);
          Object.assign(result, { status: 'CAPTURED', width: decoded.width, height: decoded.height, image_path: `target-${target.id}/${device.slug}.png` });
          if (baseline) {
            let reference: Buffer | undefined;
            try { reference = await readFile(join(baseline, `target-${target.id}`, `${device.slug}.png`)); }
            catch (error) { if ((error as NodeJS.ErrnoException).code !== 'ENOENT') throw error; }
            if (reference) {
              const { diff_image, ...comparison } = compareImages(reference, image, manifest.settings);
              await writeFile(join(directory, `${device.slug}-diff.png`), diff_image);
              Object.assign(result, comparison, { diff_path: `target-${target.id}/${device.slug}-diff.png` });
            } else { result.status = 'NO_BASELINE'; }
          }
        } catch (error) {
          Object.assign(result, { status: 'ERROR', error_code: 'SNAPSHOT_FAILED', error_message: error instanceof Error ? error.message : '不明なエラー' });
        } finally {
          try { await context?.close(); } catch {
            Object.assign(result, { status: 'ERROR', error_code: 'CONTEXT_CLOSE_FAILED', error_message: 'Browser Contextの終了に失敗しました' });
          }
          result.duration_ms = Date.now() - begin;
          results.push(result);
        }
      }
    }));
  } finally { await browser.close(); }
  results.sort((a, b) => Number(a.target_id) - Number(b.target_id) || Number(a.device_id) - Number(b.device_id));
  const require = createRequire(import.meta.url);
  const report = { runner_version: require('../../package.json').version, playwright_version: require('playwright/package.json').version, configuration: manifest, started_at: started, completed_at: new Date().toISOString(), chromium_version: browser.version(), total_snapshots: results.length, error_snapshots: results.filter(result => result.status === 'ERROR').length, snapshots: results };
  await writeFile(join(output, 'result.json'), JSON.stringify(report, null, 2) + '\n');
  return report;
}
