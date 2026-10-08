import { readdirSync, readFileSync } from 'node:fs';
import Ajv from 'ajv';
import addFormats from 'ajv-formats';

const ajv = new Ajv({ strict: true, allErrors: true });
addFormats(ajv);
export const schemas = Object.fromEntries(readdirSync(new URL('./src/', import.meta.url)).filter(x => x.endsWith('.schema.json')).map(file => [file.replace('.schema.json', ''), JSON.parse(readFileSync(new URL(`./src/${file}`, import.meta.url), 'utf8'))]));
const compiled = Object.fromEntries(Object.entries(schemas).map(([name, schema]) => [name, ajv.compile(schema)]));
const nullable = new Set(['width', 'height', 'baseline_width', 'baseline_height', 'diff_pixels', 'total_pixels', 'diff_ratio', 'http_status', 'error_code', 'error_message', 'no_baseline_reason']);
const integers = new Set(['schema_version', 'target_id', 'device_id', 'width', 'height', 'baseline_width', 'baseline_height', 'diff_pixels', 'total_pixels', 'duration_ms', 'http_status']);
export function convertMultipart(parts) {
  const result = {};
  for (const part of parts) {
    if (!Array.isArray(part) || part.length !== 2) throw new Error('odvr_invalid_payload');
    const [key, value] = part;
    if (typeof key !== 'string' || !Object.hasOwn(schemas['snapshot-result'].properties, key) || Object.hasOwn(result, key) || typeof value !== 'string') throw new Error('odvr_invalid_payload');
    if (value === 'null' && nullable.has(key)) result[key] = null;
    else if (integers.has(key)) {
      if (!/^(0|[1-9][0-9]*)$/.test(value) || !Number.isSafeInteger(Number(value))) throw new Error('odvr_invalid_payload');
      result[key] = Number(value);
    } else if (key === 'diff_ratio') {
      if (!/^(0(?:\.[0-9]{1,15})?|1(?:\.0{1,15})?)$/.test(value)) throw new Error('odvr_invalid_payload');
      result[key] = Number(value);
    } else if (key === 'dimension_changed') {
      if (!['true', 'false'].includes(value)) throw new Error('odvr_invalid_payload');
      result[key] = value === 'true';
    } else result[key] = value;
  }
  return result;
}
function requireCondition(condition) { if (!condition) throw new Error('odvr_invalid_payload'); }
function validURL(value, origin = false, https = false) {
  requireCondition(Buffer.byteLength(value) <= 2048 && !/[\x00-\x20\x7f\\]/.test(value) && !/%(?![0-9a-f]{2})|%0[0-9a-f]|%1[0-9a-f]|%7f/i.test(value));
  const url = new URL(value);
  const authority = value.match(/^https?:\/\/([^/?#]+)/i)?.[1];
  requireCondition(Boolean(authority));
  const host = authority.startsWith('[') ? authority.slice(0, authority.indexOf(']') + 1) : authority.split(':')[0];
  if (!host.startsWith('[')) requireCondition(host.toLowerCase() === url.hostname);
  if (!host.startsWith('[')) requireCondition(host.split('.').filter(Boolean).every(label => /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i.test(label)));
  requireCondition(!url.port || Number(url.port) >= 1);
  requireCondition(['http:', 'https:'].includes(url.protocol) && !url.username && !url.password && !value.match(/^https?:\/\/[^/]*@/i));
  requireCondition(!https || url.protocol === 'https:');
  requireCondition(!origin || value === url.origin);
  // 既存のPHP URL契約同様、ホストはASCIIを使用する。
  requireCondition(/^https?:\/\/[\x21-\x7e]+/i.test(value) && /^[\x21-\x7e]+$/.test(value));
  return url;
}
function walk(value, key = '') {
  if (typeof value === 'number') requireCondition(Number.isFinite(value));
  if (!value || typeof value !== 'object') return;
  if (Object.hasOwn(value, 'review_threshold')) requireCondition(value.review_threshold < value.changed_threshold);
  if (Object.hasOwn(value, 'mode') && Object.hasOwn(value, 'count')) requireCondition(value.mode === 'all' ? value.count === null : Number.isInteger(value.count) && value.count > 0);
  if (Object.hasOwn(value, 'object_id') && Object.hasOwn(value, 'post_type')) requireCondition(value.object_id === null ? value.post_type === '' : value.post_type.length > 0);
  for (const [field, child] of Object.entries(value)) {
    if (typeof child === 'string') {
      if (['url', 'site_url', 'dispatcher_url', 'callback_base', 'http_auth_origin', 'origin'].includes(field)) {
        const url = validURL(child, ['http_auth_origin', 'origin'].includes(field), ['dispatcher_url', 'callback_base', 'http_auth_origin', 'origin'].includes(field));
        if (['dispatcher_url', 'callback_base'].includes(field)) requireCondition(!child.includes('?') && !child.includes('#') && !url.search && !url.hash);
      }
      if (['created_at', 'deadline_at', 'completed_at', 'checked_at'].includes(field)) requireCondition(Number.isFinite(Date.parse(child)) && new Date(child).toISOString().slice(0, 19) + 'Z' === child);
    }
    if (field === 'allowed_origins') for (const origin of child) validURL(origin, true);
    walk(child, field);
  }
}
function semantic(name, value, context) {
  const tuples = ['progress-request','complete-request'].includes(name) ? [value.versions] : ['run-manifest','stored-run-manifest'].includes(name) ? [value.reference.versions] : ['run-environment','stored-run-environment'].includes(name) ? [value] : [];
  for (const tuple of tuples) if (tuple !== null) for (const key of ['runner','playwright','chromium']) if (tuple[key] !== null) requireCondition(tuple[key].trim().length > 0 && !/[\x00-\x1f\x7f]/.test(tuple[key]));
  if (name === 'snapshot-metadata') {
    requireCondition(value.target.id === value.reference.target_id && value.device.id === value.reference.device_id);
    requireCondition(value.reference.baseline_snapshot_id === null ? value.reference.reason !== null : value.reference.reason === null);
    if (value.result === null) requireCondition(value.result_digest === null && value.image_sha256 === null && value.diff_sha256 === null);
    else {
      semantic('snapshot-result', value.result, {});
      requireCondition(value.result.target_id === value.target.id && value.result.device_id === value.device.id && value.result_digest !== null);
      requireCondition((value.result.status === 'ERROR') === (value.image_sha256 === null));
      requireCondition(['UNCHANGED','REVIEW','CHANGED'].includes(value.result.status) === (value.diff_sha256 !== null));
    }
  }

  walk(value);
  if (name === 'run-manifest' || name === 'stored-run-manifest') {
    const wire = name === 'run-manifest';
    if (wire) requireCondition(value.run.suite_id === value.suite.id && value.run.created_at < value.run.deadline_at);
    for (const [items, keys] of [[value.targets, ['id']], [value.devices, ['id','slug']], ...(wire ? [[value.run.snapshot_states, ['snapshot_id']]] : [])]) for (const key of keys) requireCondition(new Set(items.map(x => x[key])).size === items.length);
    const pairs = new Set(value.targets.flatMap(t => value.devices.map(d => `${t.id}:${d.id}`)));
    for (const entries of [...(wire ? [value.run.snapshot_states] : []), value.reference.snapshots]) {
      const found = new Set(entries.map(x => `${x.target_id}:${x.device_id}`));
      requireCondition(entries.length === pairs.size && found.size === pairs.size && [...found].every(x => pairs.has(x)));
    }
    for (const ref of value.reference.snapshots) {
      requireCondition(ref.baseline_snapshot_id === null ? ref.reason !== null : ref.reason === null);
      if (value.reference.run_id === null) requireCondition(ref.baseline_snapshot_id === null && ref.reason === 'no_reference');
      else requireCondition(ref.reason !== 'no_reference');
    }
    if (value.reference.mode === 'specific') requireCondition(value.reference.run_id !== null);
    if (value.reference.run_id === null) requireCondition(value.reference.versions === null);
    if (name === 'stored-run-manifest' && value.http_auth_origin !== null) requireCondition(value.http_auth_origin.startsWith('https://') && value.allowed_origins.includes(value.http_auth_origin));
    if (value.reference.snapshots.some(x => x.baseline_snapshot_id !== null)) requireCondition(value.reference.versions !== null);
    for (const target of value.targets) requireCondition(value.allowed_origins.includes(new URL(target.url).origin));
  }
  if (['run-environment','stored-run-environment'].includes(name)) {
    const known = ['runner','playwright','chromium'].filter(k => value[k] !== null).length;
    requireCondition(known === 0 || known === 3);
    if (name === 'stored-run-environment' && value.completion !== null) {
      semantic('complete-request', value.completion.request, {});
      semantic('run-state', value.completion.response, {});
      requireCondition(['complete','partial','failed'].includes(value.completion.response.status));
      requireCondition(['runner','playwright','chromium'].every(k => value[k] === value.completion.request.versions[k]));
    }
  }
  if (name === 'run-state' || name === 'run-response' || name === 'run-list-response') {
    const items = name === 'run-state' ? [value] : name === 'run-response' ? [value.item] : value.items;
    for (const item of items) {
      requireCondition(item.completed_snapshots + item.error_snapshots + item.pending_snapshots === item.total_snapshots);
      const terminal = ['complete','partial','failed'].includes(item.status);
      requireCondition(['queued','running'].includes(item.status) ? item.completed_at === null : item.status === 'deleting' || item.completed_at !== null);
      if (terminal) requireCondition(item.pending_snapshots === 0);
      if (item.status === 'complete') requireCondition(item.error_snapshots === 0 && item.completed_snapshots > 0);
      if (item.status === 'partial') requireCondition(item.error_snapshots > 0 && item.completed_snapshots > 0);
    }
  }
  if (name === 'dispatch-response') requireCondition(value.status === 'accepted' ? value.runner_execution_id === null : value.runner_execution_id !== null);
  if (name === 'settings-response' || name === 'settings-patch-request') {
    const item = value.item ?? value;
    if (item.queued_timeout_seconds !== undefined && item.run_timeout_seconds !== undefined) requireCondition(item.queued_timeout_seconds < item.run_timeout_seconds);
    if (name === 'settings-response') requireCondition(item.http_auth_configured ? item.http_auth_origin !== null : item.http_auth_origin === null);
  }
  if (name === 'snapshot-result' || name === 'snapshot-response' || name === 'snapshot-list-response') {
    const items = name === 'snapshot-result' ? [value] : name === 'snapshot-response' ? [value.item] : value.items;
    for (const item of items) {
      if (item.status === 'PENDING') {
        requireCondition(['width','height','baseline_width','baseline_height','diff_pixels','total_pixels','diff_ratio','http_status','error_code','error_message','no_baseline_reason'].every(k => item[k] === null) && !item.dimension_changed && item.duration_ms === 0 && (!Object.hasOwn(item,'has_current_image') || (!item.has_current_image && !item.has_diff_image)));
        continue;
      }
      if (item.width !== null) requireCondition(item.width * item.height <= 40000000);
      if (['UNCHANGED','REVIEW','CHANGED'].includes(item.status)) {
        requireCondition(item.baseline_width * item.baseline_height <= 40000000);
        const area = Math.max(item.width,item.baseline_width) * Math.max(item.height,item.baseline_height);
        requireCondition(area <= 40000000 && area === item.total_pixels && item.diff_pixels <= area && Math.abs(item.diff_ratio - item.diff_pixels / area) <= 1e-10);
        requireCondition(item.dimension_changed === (item.width !== item.baseline_width || item.height !== item.baseline_height));
        if (context.settings) {
          const {review_threshold:r,changed_threshold:c} = context.settings;
          requireCondition(Number.isFinite(r) && Number.isFinite(c) && r >= 0 && r < c && c <= 1);
          requireCondition(item.status === (item.diff_ratio >= c ? 'CHANGED' : item.diff_ratio > r ? 'REVIEW' : 'UNCHANGED'));
        }
      }
      if (Object.hasOwn(context, 'reference_run_id')) {
        if (item.status === 'CAPTURED') requireCondition(context.reference_run_id === null);
        if (item.status === 'NO_BASELINE' || ['UNCHANGED','REVIEW','CHANGED'].includes(item.status)) requireCondition(context.reference_run_id !== null);
      }
      if (Object.hasOwn(item,'has_current_image')) requireCondition(item.has_current_image === !['PENDING','ERROR'].includes(item.status) && item.has_diff_image === ['UNCHANGED','REVIEW','CHANGED'].includes(item.status));
    }
  }
}
export function validateContract(name, value, context = {}) {
  if (!compiled[name]) throw new Error('odvr_unknown_contract');
  if (!compiled[name](value)) throw new Error(value?.schema_version !== undefined && value.schema_version !== 1 ? 'odvr_unsupported_schema_version' : 'odvr_invalid_payload');
  semantic(name, value, context);
  return value;
}

export function validateStoredVersion(kind, version) {
  if (!['settings','environment','metadata'].includes(kind) || version !== 1) throw new Error('odvr_unsupported_storage_version');
  return true;
}
