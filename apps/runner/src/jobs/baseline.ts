import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { DeviceProfile, PrototypeManifest } from '@odvr/shared';
import { safeUrl, urlFingerprint } from '../errors.js';
import { parseManifest } from '../config.js';
import { decodeImage } from '../visual/normalize-image.js';

export interface CaptureVersions {
  runner_version: string;
  playwright_version: string;
  chromium_version: string;
}
type BaselineState = 'COMPATIBLE' | 'INCOMPATIBLE' | 'METADATA_MISSING' | 'RESULT_MISSING' | 'RESULT_INVALID' | 'SNAPSHOT_MISSING' | 'IMAGE_MISSING' | 'IMAGE_INVALID' | 'READ_FAILED';
export interface BaselineCompatibility {
  state: BaselineState;
  reasons: string[];
}
type Baseline = { compatibility: BaselineCompatibility; configuration?: PrototypeManifest; versions?: CaptureVersions; snapshots?: Record<string, unknown>[] };
const outcome = (state: BaselineState, ...reasons: string[]): BaselineCompatibility => ({ state, reasons });
const isRecord = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);

export async function loadBaseline(directory: string): Promise<Baseline> {
  let raw: string;
  try { raw = await readFile(join(directory, 'result.json'), 'utf8'); }
  catch (error) {
    return { compatibility: outcome((error as NodeJS.ErrnoException).code === 'ENOENT' ? 'RESULT_MISSING' : 'READ_FAILED', 'result.json') };
  }
  let report: unknown;
  try { report = JSON.parse(raw); }
  catch { return { compatibility: outcome('RESULT_INVALID', 'result.json') }; }
  if (!isRecord(report)) return { compatibility: outcome('RESULT_INVALID', 'result.json') };
  const missing = ['configuration', 'runner_version', 'playwright_version', 'chromium_version', 'snapshots'].filter(key => report[key] === undefined);
  if (missing.length) return { compatibility: outcome('METADATA_MISSING', ...missing) };
  let configuration: PrototypeManifest;
  try { configuration = parseManifest(report.configuration); }
  catch { return { compatibility: outcome('RESULT_INVALID', 'configuration') }; }
  for (const key of ['runner_version', 'playwright_version', 'chromium_version']) {
    if (typeof report[key] !== 'string' || !report[key].trim()) return { compatibility: outcome('RESULT_INVALID', key) };
  }
  if (!Array.isArray(report.snapshots) || !report.snapshots.every(isRecord)) return { compatibility: outcome('RESULT_INVALID', 'snapshots') };
  if (report.snapshots.some(snapshot => !Number.isInteger(snapshot.target_id) || Number(snapshot.target_id) < 1 || !Number.isInteger(snapshot.device_id) || Number(snapshot.device_id) < 1 || typeof snapshot.url !== 'string' || typeof snapshot.status !== 'string')) {
    return { compatibility: outcome('RESULT_INVALID', 'snapshots') };
  }
  return { compatibility: outcome('COMPATIBLE'), configuration, versions: report as unknown as CaptureVersions, snapshots: report.snapshots };
}

// 順序と重複はマスク対象・許可Originの意味を変えない。
const setValue = (values: string[]) => JSON.stringify([...new Set(values)].sort());
export async function inspectBaseline(baseline: Baseline, directory: string, manifest: PrototypeManifest, target: PrototypeManifest['targets'][number], device: DeviceProfile, versions: CaptureVersions): Promise<{ compatibility: BaselineCompatibility; image?: Buffer }> {
  if (baseline.compatibility.state !== 'COMPATIBLE') return { compatibility: baseline.compatibility };
  const configuration = baseline.configuration!;
  const oldTarget = configuration.targets.find(item => item.id === target.id);
  const oldDevice = configuration.devices.find(item => item.id === device.id);
  const matches = baseline.snapshots!.filter(item => item.target_id === target.id && item.device_id === device.id);
  const snapshot = matches[0];
  const fingerprint = isRecord(snapshot?.metadata) ? snapshot.metadata.url_fingerprint : undefined;
  const matchesUrl = (value: unknown) => fingerprint === undefined ? value === target.url :
    value === safeUrl(target.url) && fingerprint === urlFingerprint(target.url);
  const reasons: string[] = [];
  if (!oldTarget) reasons.push('target_id');
  else if (!matchesUrl(oldTarget.url)) reasons.push('target.url');
  if (!oldDevice) reasons.push('device_id');
  else {
    for (const key of ['viewport_width', 'viewport_height', 'user_agent', 'device_scale_factor', 'is_mobile', 'has_touch'] as const) {
      if (oldDevice[key] !== device[key]) reasons.push(`device.${key}`);
    }
  }
  for (const key of ['navigation_timeout_ms', 'image_timeout_ms', 'lazy_load'] as const) {
    if (configuration.settings[key] !== manifest.settings[key]) reasons.push(`settings.${key}`);
  }
  if (setValue(configuration.settings.ignore_selectors) !== setValue(manifest.settings.ignore_selectors)) reasons.push('settings.ignore_selectors');
  if (setValue(configuration.allowed_origins) !== setValue(manifest.allowed_origins)) reasons.push('allowed_origins');
  for (const key of ['runner_version', 'playwright_version', 'chromium_version'] as const) {
    if (baseline.versions![key] !== versions[key]) reasons.push(key);
  }
  if (reasons.length) return { compatibility: outcome('INCOMPATIBLE', ...reasons) };
  if (!matches.length) return { compatibility: outcome('SNAPSHOT_MISSING', 'snapshots') };
  const path = `target-${target.id}/${oldDevice!.slug}.png`;
  if (matches.length !== 1 || !matchesUrl(snapshot.url) || !['CAPTURED', 'UNCHANGED', 'REVIEW', 'CHANGED', 'NO_BASELINE'].includes(String(snapshot.status)) || snapshot.image_path !== path || !Number.isInteger(snapshot.width) || !Number.isInteger(snapshot.height)) {
    return { compatibility: outcome('RESULT_INVALID', 'snapshot') };
  }
  let image: Buffer;
  // JSON内の任意パスではなく、検証済みID・slugから画像を特定する。
  try { image = await readFile(join(directory, path)); }
  catch (error) { return { compatibility: outcome((error as NodeJS.ErrnoException).code === 'ENOENT' ? 'IMAGE_MISSING' : 'READ_FAILED', path) }; }
  try {
    const decoded = decodeImage(image);
    if (decoded.width !== snapshot.width || decoded.height !== snapshot.height) return { compatibility: outcome('RESULT_INVALID', 'snapshot.dimensions') };
  } catch { return { compatibility: outcome('IMAGE_INVALID', path) }; }
  return { compatibility: outcome('COMPATIBLE'), image };
}

/** 製品Manifestの撮影互換性。プロトタイプの保存形式は使用しない。 */
export function productCaptureCompatible(previous: import('@odvr/shared').RunManifest, current: import('@odvr/shared').RunManifest, targetId: number, deviceId: number): boolean {
  const oldTarget = previous.targets.find(target => target.id === targetId);
  const target = current.targets.find(item => item.id === targetId);
  const oldDevice = previous.devices.find(device => device.id === deviceId);
  const device = current.devices.find(item => item.id === deviceId);
  if (!oldTarget || !target || !oldDevice || !device || oldTarget.url !== target.url) return false;
  for (const key of ['viewport_width', 'viewport_height', 'device_scale_factor', 'user_agent', 'is_mobile', 'has_touch'] as const) {
    if (oldDevice[key] !== device[key]) return false;
  }
  for (const key of ['settings_version', 'navigation_timeout_ms', 'image_timeout_ms', 'lazy_load'] as const) {
    if (previous.settings[key] !== current.settings[key]) return false;
  }
  return setValue(previous.settings.ignore_selectors) === setValue(current.settings.ignore_selectors) && setValue(previous.allowed_origins) === setValue(current.allowed_origins);
}

/** 開始時に固定された参照Versionと、今回の実Versionが全て一致すること。 */
export function productVersionsCompatible(reference: import('@odvr/shared').RunManifest['reference']['versions'], actual: import('@odvr/shared').ProgressRequest['versions']): boolean {
  return reference !== null && (['runner', 'playwright', 'chromium'] as const).every(key => reference[key].trim().length > 0 && reference[key] === actual[key]);
}
