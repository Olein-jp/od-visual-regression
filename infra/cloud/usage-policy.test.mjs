import test from 'node:test';
import assert from 'node:assert/strict';
import { checkedUsagePolicy, estimateRun } from './usage-policy.mjs';
import { foundationPlan } from './plan.mjs';
import { deploymentPlan } from './deployment.mjs';
const now = Date.parse('2026-10-10T01:00:00.000Z');
const policy = () => ({ version: 1, profile: 'minimal_validation', expires_at: new Date(now + 3600000).toISOString(),
  max_runs: 2, max_active: 1, spend_limit_usd: 1, management_reserve_usd: 0.5 });
test('1ページ1Deviceの更新前後2回を1ドルの計画枠に収める', () => {
  const estimate = estimateRun('minimal_validation');
  assert.equal(estimate.snapshots, 1);
  assert.throws(() => estimateRun('__proto__'));
  assert.throws(() => estimateRun('constructor'));
  assert.ok(estimate.reserved_usd * 2 + 0.5 <= 1);
  assert.ok(estimateRun('update_test').reserved_usd > estimate.reserved_usd);
  assert.deepEqual(checkedUsagePolicy(policy(), now), policy());
});
test('期限不明・失効・過大試験量・予算不足を拒否する', () => {
  for (const change of [{ expires_at: null }, { expires_at: new Date(now).toISOString() },
    { expires_at: new Date(now + 32 * 86400000).toISOString() }, { max_runs: 5 }, { max_active: 2 },
    { spend_limit_usd: 0.1 }, { management_reserve_usd: 0 }, { profile: 'arbitrary' },
    { ignore_limits: true }, { max_runs: 1.5 }, { version: 2 }])
    assert.throws(() => checkedUsagePolicy({ ...policy(), ...change }, now));
});
const environment = () => ({ schema_version: 2, environment: 'staging', profile: 'cloud', configuration: 'odvr-staging',
  operator_account: 'operator@fixture.invalid', project_id: 'odvr-fixture-staging', project_number: '123456789012',
  run_secret_project_id: 'odvr-fixture-secrets', run_secret_project_number: '223456789012', region: 'asia-northeast1', subnet_cidr: '10.39.0.0/26',
  github: { repository: 'Olein-jp/od-visual-regression', repository_id: '1409590981', owner_id: '28924629' },
  sites: { fixture: { callback_base: 'https://wordpress.odvr-fixture.com/wp-json/odvr/v1/runner', shared_secret: 'odvr-fixture-shared', shared_version: '1' } },
  usage_policy: { ...policy(), expires_at: new Date(Date.now() + 3600000).toISOString() }, cleanup_after_days: 1 });
test('基盤計画に常設NAT/IP・有料Registryを入れず、Controller完成まで起動しない', () => {
  const plan = foundationPlan(environment());
  assert.ok(plan.commands.every(command => !command.argv.includes('nats') && !command.argv.includes('addresses') && !command.argv.includes('artifacts')));
  assert.equal(plan.network_lifecycle.idle_gateway_count, 0);
  assert.equal(plan.network_lifecycle.idle_external_ip_count, 0);
  assert.equal(plan.run_estimate.snapshots, 1);
  assert.equal(plan.status, 'preparation_only');
});
test('少量検証Jobは5分・再試行1回・all-traffic、DispatcherはNAT不要', () => {
  const release = component => ({ component, git_revision: 'a'.repeat(40), config_version: '1', image: `ghcr.io/olein-jp/odvr-${component}@sha256:${'b'.repeat(64)}` });
  const runner = deploymentPlan(environment(), release('runner'));
  assert.ok(runner.argv.includes('--task-timeout=300s'));
  assert.ok(runner.argv.includes('--vpc-egress=all-traffic'));
  const dispatcher = deploymentPlan(environment(), release('dispatcher'));
  assert.ok(!dispatcher.argv.some(arg => arg.startsWith('--network=') || arg.startsWith('--vpc-egress=')));
  assert.ok(dispatcher.argv.includes('--concurrency=1'));
  assert.ok(dispatcher.argv.includes('--clear-network'));
  assert.ok(dispatcher.argv.includes('--clear-vpc-connector'));
});
