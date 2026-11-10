import test from 'node:test';
import assert from 'node:assert/strict';
import { deploymentPlan, runnerRegistration } from './deployment.mjs';

const environment = { schema_version: 1, environment: 'staging', profile: 'cloud', configuration: 'odvr-staging',
  operator_account: 'operator@fixture.invalid', project_id: 'odvr-fixture-staging', project_number: '123456789012',
  run_secret_project_id: 'odvr-fixture-secrets', run_secret_project_number: '223456789012',
  region: 'asia-northeast1', subnet_cidr: '10.39.0.0/26',
  github: { repository: 'Olein-jp/od-visual-regression', repository_id: '1409590981', owner_id: '28924629' },
  sites: { fixture: { callback_base: 'https://wordpress.odvr-fixture.com/wp-json/odvr/v1/runner',
    shared_secret: 'odvr-fixture-shared', shared_version: '1' } }, budget_usd: 10, cleanup_after_days: 1 };
const release = (component = 'runner') => ({ component, git_revision: 'a'.repeat(40), config_version: '2',
  image: `asia-northeast1-docker.pkg.dev/odvr-fixture-staging/odvr-${component}/${component}@sha256:${'b'.repeat(64)}` });

test('Job/Serviceは固定digest・version・runtime SA・all-trafficで配備し、暗黙公開や実行をしない', () => {
  for (const component of ['runner', 'dispatcher']) {
    const plan = deploymentPlan(environment, release(component));
    assert.equal(plan.approval_required, true);
    for (const flag of ['--vpc-egress=all-traffic', '--network=odvr-staging', '--subnet=odvr-staging',
      '--network-tags=odvr-staging', '--execution-environment=gen2', '--project=odvr-fixture-staging',
      '--configuration=odvr-staging', '--account=operator@fixture.invalid']) assert.ok(plan.argv.includes(flag));
    assert.ok(!plan.argv.some(arg => /latest|allow-unauthenticated$/.test(arg) && !arg.startsWith('--no-')));
    assert.ok(!plan.argv.includes('--execute-now'));
    assert.equal(plan.argv.at(-4), `--set-secrets=/etc/odvr/${component}.json=odvr-${component}-config:2`);
  }
  const job = deploymentPlan(environment, release()).argv;
  for (const flag of ['--tasks=1', '--parallelism=1', '--task-timeout=1800s', '--max-retries=1',
    '--cpu=2', '--memory=2Gi', '--command=node,apps/runner/dist/job.js', '--args=']) assert.ok(job.includes(flag));
  assert.ok(job.indexOf('--tasks=1') < job.indexOf('--container=runner'));
  assert.ok(job.indexOf('--cpu=2') > job.indexOf('--container=runner'));
  const service = deploymentPlan(environment, release('dispatcher')).argv;
  for (const flag of ['--no-allow-unauthenticated', '--invoker-iam-check', '--max=2', '--min=0', '--timeout=60s', '--concurrency=8']) assert.ok(service.includes(flag));
});
test('別project/region/componentのimage、tag、可変version、任意引数・秘密設定を拒否する', () => {
  for (const change of [{ image: release().image.replace('staging', 'production') },
    { image: release().image.replace('asia-northeast1', 'us-central1') }, { image: release('dispatcher').image },
    { image: 'runner:latest' }, { config_version: 'latest' }, { config_version: 2 }, { git_revision: 'main' },
    { command: 'echo' }, { shared_secret_payload: 'fixture' }, { component: 'other' }])
    assert.throws(() => deploymentPlan(environment, { ...release(), ...change }));
});
test('Runner登録設定はRun専用project番号と固定callbackのみを含む', () => {
  assert.deepEqual(runnerRegistration(environment), { schema_version: 1, profile: 'cloud',
    secret_project: '223456789012', sites: { fixture: { callback_base: environment.sites.fixture.callback_base } } });
});
