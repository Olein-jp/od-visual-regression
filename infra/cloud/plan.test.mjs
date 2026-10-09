import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { checkedEnvironment, assertSeparateEnvironments, foundationPlan, planHash } from './plan.mjs';

const fixture = () => ({
  schema_version: 1, environment: 'staging', profile: 'cloud', configuration: 'odvr-staging',
  operator_account: 'operator@fixture.invalid', project_id: 'odvr-fixture-staging', project_number: '123456789012',
  run_secret_project_id: 'odvr-fixture-secrets', run_secret_project_number: '223456789012',
  region: 'asia-northeast1', subnet_cidr: '10.39.0.0/26',
  github: { repository: 'Olein-jp/od-visual-regression', repository_id: '1409590981', owner_id: '28924629' },
  sites: { 'staging-1': { callback_base: 'https://wordpress.odvr-fixture.com/wp-json/odvr/v1/runner',
    shared_secret: 'odvr-fixture-shared', shared_version: '1' } }, budget_usd: 10, cleanup_after_days: 1,
});

test('未確定のテンプレートはクラウド計画に使えない', async () => {
  for (const environment of ['staging', 'production']) {
    const value = JSON.parse(await readFile(`infra/environments/${environment}.example.json`, 'utf8'));
    assert.throws(() => checkedEnvironment(value));
  }
});
test('local混在・秘密・未知設定・project同一・不正CIDRを拒否する', () => {
  const changes = [
    { profile: 'local' }, { local_destination: {} }, { runner_token: 'dummy' },
    { run_secret_project_id: 'odvr-fixture-staging' }, { run_secret_project_number: '123456789012' },
    { project_number: 123456789012 }, { subnet_cidr: '10.39.0.0/27' }, { subnet_cidr: '10.39.0.1/26' },
    { subnet_cidr: '169.254.0.0/16' }, { subnet_cidr: '10.39.0.0/26/extra' },
    { configuration: 'default' }, { operator_account: 'bad;account' }, { budget_usd: 0 },
    { cleanup_after_days: 8 }, { region: 'asia-northeast1;echo' },
  ];
  for (const change of changes) assert.throws(() => checkedEnvironment({ ...fixture(), ...change }));
});
test('callbackの秘密・redirect要素・内部先と未固定versionを拒否する', () => {
  for (const callback of ['http://wordpress.odvr-fixture.com/wp-json/odvr/v1/runner',
    'https://user:password@wordpress.odvr-fixture.com/wp-json/odvr/v1/runner',
    'https://wordpress.odvr-fixture.com/wp-json/odvr/v1/runner?token=dummy',
    'https://wordpress.odvr-fixture.com/wp-json/odvr/v1/runner#fragment',
    'https://127.0.0.1/wp-json/odvr/v1/runner', 'https://metadata.google.internal/wp-json/odvr/v1/runner',
    'https://wordpress.test/wp-json/odvr/v1/runner']) {
    const value = fixture(); value.sites['staging-1'].callback_base = callback;
    assert.throws(() => checkedEnvironment(value));
  }
  for (const version of ['latest', 1, '0', '01']) {
    const value = fixture(); value.sites['staging-1'].shared_version = version;
    assert.throws(() => checkedEnvironment(value));
  }
});
test('WIFは対象数値IDとmainの明示Environment/workflowに限定する', () => {
  const value = fixture(); value.github.repository_id = '1409590982';
  assert.throws(() => checkedEnvironment(value));
  const plan = foundationPlan(fixture());
  const provider = plan.commands.find(command => command.argv.includes('create-oidc'));
  const condition = provider.argv.find(arg => arg.startsWith('--attribute-condition='));
  for (const expected of ['1409590981', '28924629', "assertion.ref == 'refs/heads/main'",
    'environment:staging', 'cloud-release.yml@refs/heads/main']) assert.ok(condition.includes(expected));
  assert.ok(provider.argv.includes(`--allowed-audiences=${plan.audience}`));
  assert.equal(plan.commands.filter(command => command.argv.some(arg => arg.includes('workloadIdentityUser'))).length, 0);
});
test('全操作に対象project・account・configurationがあり、cloudの実行や公開はない', () => {
  const plan = foundationPlan(fixture());
  for (const { target, argv } of plan.commands) {
    assert.ok(argv.includes(`--project=${target}`));
    assert.ok(argv.includes('--account=operator@fixture.invalid'));
    assert.ok(argv.includes('--configuration=odvr-staging'));
    assert.ok(!argv.includes('--allow-unauthenticated'));
    assert.ok(!argv.includes('--execute-now'));
    assert.ok(!argv.includes('allUsers'));
    assert.ok(!argv.some(arg => arg.includes('--data-file') || arg.includes('--secret-data')));
  }
  assert.equal(plan.status, 'preparation_only');
  assert.equal(plan.approval_required, true);
  assert.equal(plan.budget_is_hard_limit, false);
});
test('runtimeにJob変更・Secret payload読取・広い管理roleを付与しない', () => {
  const plan = foundationPlan(fixture());
  const permissions = plan.commands.filter(command => command.argv.includes('roles'))
    .flatMap(command => command.argv.filter(arg => arg.startsWith('--permissions=')));
  for (const forbidden of ['run.jobs.update', 'secretmanager.versions.access', 'iam.serviceAccounts.actAs'])
    assert.ok(permissions.every(value => !value.includes(forbidden)));
  for (const command of plan.commands) assert.ok(command.argv.every(arg => !/^--role=roles\/(?:owner|editor|run.admin|secretmanager.admin)$/.test(arg)));
  const runSecrets = plan.commands.find(command => command.argv.includes('odvr_runSecrets'));
  assert.equal(runSecrets.target, fixture().run_secret_project_id);
  const condition = plan.commands.flatMap(command => command.argv).find(arg => arg.startsWith('--condition=title=odvr_job'));
  assert.ok(condition.includes('/jobs/odvr-runner'));
  assert.ok(condition.includes('/executions/'));
});
test('非秘密configのRunner Accessor例外は専用Secretだけに付く', () => {
  const plan = foundationPlan(fixture());
  const accessor = plan.commands.filter(command => command.argv.includes(`--member=serviceAccount:${plan.accounts.runner}`)
    && command.argv.includes('--role=roles/secretmanager.secretAccessor'));
  assert.equal(accessor.length, 1);
  assert.ok(accessor[0].argv.includes('odvr-runner-config'));
  assert.ok(!accessor[0].argv.includes('projects'));
});
test('private denyがweb allowより優先し、UDPと別portはdefault denyになる', () => {
  const rules = foundationPlan(fixture()).commands.filter(command => command.argv.includes('firewall-rules'));
  const privateDeny = rules.find(rule => rule.argv.includes('--priority=100'));
  assert.ok(privateDeny.argv.includes('--action=DENY'));
  for (const cidr of ['10.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16'])
    assert.ok(privateDeny.argv.some(arg => arg.startsWith('--destination-ranges=') && arg.includes(cidr)));
  assert.ok(rules.some(rule => rule.argv.includes('--priority=200') && rule.argv.includes('--rules=tcp:80,tcp:443')));
  assert.ok(rules.some(rule => rule.argv.includes('--priority=300') && rule.argv.includes('--action=DENY') && rule.argv.includes('--rules=all')));
});
test('staging/productionでproject/番号を再利用できない', () => {
  const production = { ...fixture(), environment: 'production', configuration: 'odvr-production' };
  assert.throws(() => assertSeparateEnvironments(fixture(), production));
  production.project_id = 'odvr-fixture-production'; production.project_number = '323456789012';
  production.run_secret_project_id = 'odvr-production-secrets'; production.run_secret_project_number = '423456789012';
  assert.doesNotThrow(() => assertSeparateEnvironments(fixture(), production));
});
test('計画hashは決定的で、targetや予算が変われば変わる', () => {
  assert.equal(planHash(foundationPlan(fixture())), planHash(foundationPlan(fixture())));
  assert.notEqual(planHash(foundationPlan(fixture())), planHash(foundationPlan({ ...fixture(), budget_usd: 20 })));
});
test('CLIは不正入力をechoせず失敗し、gcloudがなくてもテストできる', () => {
  const result = spawnSync(process.execPath, ['infra/scripts/cloud-plan.mjs', 'infra/environments/staging.example.json'],
    { encoding: 'utf8', env: { PATH: '' } });
  assert.equal(result.status, 1);
  assert.equal(result.stdout, '');
  assert.match(result.stderr, /クラウド計画を生成できません/);
  assert.ok(!result.stderr.includes('operator_account'));
});
