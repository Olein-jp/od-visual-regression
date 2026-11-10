const exact = (value, names) => value && typeof value === 'object' && !Array.isArray(value)
  && Object.keys(value).length === names.length && names.every(name => Object.hasOwn(value, name));
const fail = () => { throw new Error('クラウドの利用上限・期限を確認してください。'); };

/** 検証の撮影量と製品の対応容量を分ける。無料枠は見積りから控除しない。 */
export const profiles = Object.freeze({
  minimal_validation: Object.freeze({ targets: 1, devices: 1, task_seconds: 300, retries: 1,
    cpu: 2, memory_gib: 2, browser_concurrency: 1, outbound_mib: 128, inbound_mib: 128,
    network_minutes: 30 }),
  update_test: Object.freeze({ targets: 20, devices: 3, task_seconds: 1800, retries: 1,
    cpu: 2, memory_gib: 2, browser_concurrency: 2, outbound_mib: 6144, inbound_mib: 2048,
    network_minutes: 90 }),
});
// 2026-10-10 Tokyo Tier 1 / Premium Tier。配備時には料金資料を再確認する。
export const rates = Object.freeze({ cpu_second: 0.000018, memory_gib_second: 0.000002,
  outbound_gib: 0.23, nat_gib: 0.045, network_hour: 0.06 });
export function estimateRun(profileName) {
  if (!Object.hasOwn(profiles, profileName)) fail();
  const profile = profiles[profileName];
  const seconds = profile.task_seconds * (profile.retries + 1) + 120;
  const cpu = seconds * profile.cpu * rates.cpu_second;
  const memory = seconds * profile.memory_gib * rates.memory_gib_second;
  const outbound = profile.outbound_mib / 1024 * rates.outbound_gib;
  const nat = (profile.outbound_mib + profile.inbound_mib) / 1024 * rates.nat_gib;
  const network = profile.network_minutes / 60 * rates.network_hour;
  const major = cpu + memory + outbound + nat + network;
  return { profile: profileName, snapshots: profile.targets * profile.devices,
    cpu, memory, outbound, nat, network, major_usd: major,
    // 操作費と誤差用の予約。これは実請求の絶対上限ではない。
    reserved_usd: Math.ceil((major + 0.05) * 100) / 100 };
}
export function checkedUsagePolicy(value, now = Date.now()) {
  if (!exact(value, ['version', 'profile', 'expires_at', 'max_runs', 'max_active', 'spend_limit_usd', 'management_reserve_usd'])
    || value.version !== 1 || !Object.hasOwn(profiles, value.profile)
    || typeof value.expires_at !== 'string' || !/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/.test(value.expires_at)
    || !Number.isFinite(now) || !Number.isSafeInteger(Date.parse(value.expires_at))
    || new Date(value.expires_at).toISOString() !== value.expires_at
    || Date.parse(value.expires_at) <= now || Date.parse(value.expires_at) > now + 31 * 86400000
    || !Number.isSafeInteger(value.max_runs) || value.max_runs < 1 || value.max_runs > 100
    || !Number.isSafeInteger(value.max_active) || value.max_active < 1 || value.max_active > 2
    || !Number.isFinite(value.spend_limit_usd) || value.spend_limit_usd <= 0
    || !Number.isFinite(value.management_reserve_usd) || value.management_reserve_usd < 0.1) fail();
  if (value.profile === 'minimal_validation' && (value.max_runs > 4 || value.max_active !== 1)) fail();
  const estimated = estimateRun(value.profile);
  if (estimated.reserved_usd * value.max_runs + value.management_reserve_usd > value.spend_limit_usd) fail();
  return structuredClone(value);
}
