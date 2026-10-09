import { isIP } from 'node:net';
import { createHash } from 'node:crypto';

const keys = (value, expected) => value && typeof value === 'object' && !Array.isArray(value)
  && Object.keys(value).length === expected.length && expected.every(key => Object.hasOwn(value, key));
const project = value => typeof value === 'string' && /^[a-z][a-z0-9-]{4,28}[a-z0-9]$/.test(value);
const number = value => typeof value === 'string' && /^[1-9][0-9]{5,19}$/.test(value);
const fail = () => { throw new Error('クラウドの環境設定を確認してください。'); };

/** 非秘密の設定だけを扱う。未確定値・未知キー・local profileは計画生成前に拒否する。 */
export function checkedEnvironment(value) {
  if (!keys(value, ['schema_version', 'environment', 'profile', 'configuration', 'operator_account',
    'project_id', 'project_number', 'run_secret_project_id', 'run_secret_project_number', 'region',
    'subnet_cidr', 'github', 'sites', 'budget_usd', 'cleanup_after_days'])) fail();
  if (value.schema_version !== 1 || value.profile !== 'cloud' || !['staging', 'production'].includes(value.environment)
    || value.configuration !== `odvr-${value.environment}` || !project(value.project_id)
    || !project(value.run_secret_project_id) || value.project_id === value.run_secret_project_id
    || !number(value.project_number) || !number(value.run_secret_project_number)
    || value.project_number === value.run_secret_project_number
    || typeof value.region !== 'string' || !/^[a-z]+-[a-z]+[1-9][0-9]?$/.test(value.region)
    || typeof value.operator_account !== 'string' || !/^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(value.operator_account)
    || !Number.isFinite(value.budget_usd) || value.budget_usd <= 0
    || !Number.isInteger(value.cleanup_after_days) || value.cleanup_after_days < 1
    || value.cleanup_after_days > (value.environment === 'staging' ? 7 : 30)) fail();
  const [address, mask, extra] = typeof value.subnet_cidr === 'string' ? value.subnet_cidr.split('/') : [];
  if (extra !== undefined || isIP(address ?? '') !== 4 || !/^(?:1[6-9]|2[0-6])$/.test(mask ?? '')) fail();
  const octets = address.split('.').map(Number);
  if (!(octets[0] === 10 || (octets[0] === 172 && octets[1] >= 16 && octets[1] <= 31)
    || (octets[0] === 192 && octets[1] === 168))) fail();
  const integer = octets.reduce((result, octet) => result * 256 + octet, 0);
  if (integer % (2 ** (32 - Number(mask))) !== 0) fail();
  if (!keys(value.github, ['repository', 'repository_id', 'owner_id'])
    || value.github.repository !== 'Olein-jp/od-visual-regression'
    || value.github.repository_id !== '1409590981' || value.github.owner_id !== '28924629') fail();
  if (!value.sites || typeof value.sites !== 'object' || Array.isArray(value.sites)
    || Object.keys(value.sites).length < 1 || Object.keys(value.sites).length > 100) fail();
  for (const [id, site] of Object.entries(value.sites)) {
    if (!/^[A-Za-z0-9_-]{1,100}$/.test(id) || !keys(site, ['callback_base', 'shared_secret', 'shared_version'])
      || typeof site.callback_base !== 'string' || site.callback_base.length > 2048
      || typeof site.shared_secret !== 'string' || !/^[A-Za-z0-9_-]{1,255}$/.test(site.shared_secret)
      || typeof site.shared_version !== 'string' || !/^[1-9][0-9]{0,9}$/.test(site.shared_version)) fail();
    let url; try { url = new URL(site.callback_base); } catch { fail(); }
    if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash
      || url.href !== site.callback_base || !url.pathname.endsWith('/wp-json/odvr/v1/runner')
      || isIP(url.hostname.replace(/^\[|\]$/g, '')) || !url.hostname.includes('.')
      || /(?:^|\.)(?:localhost|local|internal|test|invalid|example)$/.test(url.hostname)
      || url.hostname === 'metadata.google.internal') fail();
  }
  return structuredClone(value);
}

/** project IDと番号の組の実在性は、構築前にgcloud projects describeで別途確認する。 */
export function assertSeparateEnvironments(left, right) {
  const first = checkedEnvironment(left); const second = checkedEnvironment(right);
  if (first.environment === second.environment || [first.project_id, first.run_secret_project_id]
    .some(id => [second.project_id, second.run_secret_project_id].includes(id))
    || [first.project_number, first.run_secret_project_number]
      .some(id => [second.project_number, second.run_secret_project_number].includes(id))) fail();
}

export const runtimeRoles = Object.freeze({
  job: ['run.jobs.get', 'run.jobs.run', 'run.jobs.runWithOverrides', 'run.executions.get', 'run.executions.list'],
  operations: ['run.operations.get'],
  ledger: ['datastore.databases.get', 'datastore.entities.get', 'datastore.entities.list',
    'datastore.entities.create', 'datastore.entities.update', 'datastore.entities.delete'],
  runSecrets: ['secretmanager.secrets.create', 'secretmanager.secrets.get', 'secretmanager.secrets.list',
    'secretmanager.secrets.delete', 'secretmanager.secrets.getIamPolicy', 'secretmanager.secrets.setIamPolicy',
    'secretmanager.versions.add', 'secretmanager.versions.get', 'secretmanager.versions.list'],
});

export function foundationPlan(input) {
  const config = checkedEnvironment(input);
  const { project_id: projectId, project_number: projectNumber, run_secret_project_id: secretProject,
    region, environment } = config;
  const prefix = `odvr-${environment}`;
  const accounts = Object.fromEntries(['dispatcher', 'runner', 'scheduler', 'build', 'deploy']
    .map(name => [name, `odvr-${name}@${projectId}.iam.gserviceaccount.com`]));
  const commands = [];
  // argv配列の計画。shellへの貼付けやクラウドの暗黙の実行は行わない。
  const add = (phase, target, args) => commands.push({ phase, target, argv: ['gcloud', ...args,
    `--configuration=${config.configuration}`, `--account=${config.operator_account}`, `--project=${target}`] });
  const binding = (phase, target, member, role, condition = 'None') => add(phase, target,
    ['projects', 'add-iam-policy-binding', target, `--member=serviceAccount:${member}`, `--role=${role}`, `--condition=${condition}`]);
  add('preflight', projectId, ['projects', 'describe', projectId, '--format=json(projectId,projectNumber,lifecycleState)']);
  add('preflight', secretProject, ['projects', 'describe', secretProject, '--format=json(projectId,projectNumber,lifecycleState)']);
  add('apis', projectId, ['services', 'enable', 'run.googleapis.com', 'artifactregistry.googleapis.com',
    'firestore.googleapis.com', 'secretmanager.googleapis.com', 'cloudscheduler.googleapis.com',
    'compute.googleapis.com', 'iam.googleapis.com', 'iamcredentials.googleapis.com', 'sts.googleapis.com']);
  add('apis', secretProject, ['services', 'enable', 'secretmanager.googleapis.com', 'iam.googleapis.com']);
  for (const name of Object.keys(accounts)) add('identities', projectId,
    ['iam', 'service-accounts', 'create', `odvr-${name}`, `--display-name=ODVR ${environment} ${name}`]);
  for (const component of ['runner', 'dispatcher']) add('registry', projectId,
    ['artifacts', 'repositories', 'create', `odvr-${component}`, '--repository-format=docker',
      `--location=${region}`, '--immutable-tags']);
  add('ledger', projectId, ['firestore', 'databases', 'create', '--database=(default)',
    `--location=${region}`, '--type=firestore-native', '--delete-protection']);
  add('network', projectId, ['compute', 'networks', 'create', prefix, '--subnet-mode=custom', '--bgp-routing-mode=regional']);
  add('network', projectId, ['compute', 'networks', 'subnets', 'create', prefix, `--network=${prefix}`,
    `--region=${region}`, `--range=${config.subnet_cidr}`, '--stack-type=IPV4_ONLY', '--enable-private-ip-google-access']);
  add('network', projectId, ['compute', 'routers', 'create', prefix, `--network=${prefix}`, `--region=${region}`]);
  add('network', projectId, ['compute', 'routers', 'nats', 'create', prefix, `--router=${prefix}`,
    `--region=${region}`, `--nat-custom-subnet-ip-ranges=${prefix}`, '--auto-allocate-nat-external-ips']);
  const firewall = (suffix, priority, action, rules, ranges) => add('network', projectId,
    ['compute', 'firewall-rules', 'create', `${prefix}-${suffix}`, `--network=${prefix}`, '--direction=EGRESS',
      `--priority=${priority}`, `--action=${action}`, `--rules=${rules}`, `--destination-ranges=${ranges}`,
      `--target-tags=${prefix}`]);
  // VPC内の別resolverへ迂回させない。Cloud Runの既定resolver/MetadataはVPC外も実測する。
  firewall('deny-private', 100, 'DENY', 'all',
    '0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.0.2.0/24,192.88.99.0/24,192.168.0.0/16,198.18.0.0/15,198.51.100.0/24,203.0.113.0/24,224.0.0.0/4,240.0.0.0/4');
  firewall('allow-web', 200, 'ALLOW', 'tcp:80,tcp:443', '0.0.0.0/0');
  firewall('deny-other', 300, 'DENY', 'all', '0.0.0.0/0');
  for (const [name, permissions] of Object.entries(runtimeRoles)) {
    const target = name === 'runSecrets' ? secretProject : projectId;
    add('iam', target, ['iam', 'roles', 'create', `odvr_${name}`, `--title=ODVR ${name}`,
      `--permissions=${permissions.join(',')}`, '--stage=GA']);
    const jobName = `projects/${projectId}/locations/${region}/jobs/odvr-runner`;
    const condition = name === 'job' ? `title=odvr_job,expression=resource.name == '${jobName}' || resource.name.startsWith('${jobName}/executions/')`
      : 'None';
    binding('iam', target, accounts.dispatcher, `projects/${target}/roles/odvr_${name}`, condition);
  }
  // 非秘密configをread-only Secret volumeへ置く。TokenのAccessorとは別の例外である。
  for (const component of ['runner', 'dispatcher']) {
    add('configuration', projectId, ['secrets', 'create', `odvr-${component}-config`, '--replication-policy=automatic']);
    add('configuration', projectId, ['secrets', 'add-iam-policy-binding', `odvr-${component}-config`,
      `--member=serviceAccount:${accounts[component]}`, '--role=roles/secretmanager.secretAccessor', '--condition=None']);
  }
  for (const secret of new Set(Object.values(config.sites).map(site => site.shared_secret)))
    add('iam', projectId, ['secrets', 'add-iam-policy-binding', secret,
      `--member=serviceAccount:${accounts.dispatcher}`, '--role=roles/secretmanager.secretAccessor', '--condition=None']);
  for (const component of ['runner', 'dispatcher']) {
    add('iam', projectId, ['artifacts', 'repositories', 'add-iam-policy-binding', `odvr-${component}`,
      `--location=${region}`, `--member=serviceAccount:${accounts.build}`, '--role=roles/artifactregistry.writer', '--condition=None']);
    add('iam', projectId, ['artifacts', 'repositories', 'add-iam-policy-binding', `odvr-${component}`,
      `--location=${region}`, `--member=serviceAccount:service-${projectNumber}@serverless-robot-prod.iam.gserviceaccount.com`,
      '--role=roles/artifactregistry.reader', '--condition=None']);
    add('iam', projectId, ['iam', 'service-accounts', 'add-iam-policy-binding', accounts[component],
      `--member=serviceAccount:${accounts.deploy}`, '--role=roles/iam.serviceAccountUser', '--condition=None']);
  }
  const provider = `projects/${projectNumber}/locations/global/workloadIdentityPools/${prefix}/providers/github`;
  const audience = `https://iam.googleapis.com/${provider}`;
  add('wif', projectId, ['iam', 'workload-identity-pools', 'create', prefix, '--location=global', `--display-name=${prefix}`]);
  add('wif', projectId, ['iam', 'workload-identity-pools', 'providers', 'create-oidc', 'github', '--location=global',
    `--workload-identity-pool=${prefix}`, '--issuer-uri=https://token.actions.githubusercontent.com',
    '--attribute-mapping=google.subject=assertion.sub,attribute.repository_id=assertion.repository_id',
    `--allowed-audiences=${audience}`,
    `--attribute-condition=assertion.repository_id == '${config.github.repository_id}' && assertion.repository_owner_id == '${config.github.owner_id}' && assertion.ref == 'refs/heads/main' && assertion.sub == 'repo:${config.github.repository}:environment:${environment}' && assertion.workflow_ref == '${config.github.repository}/.github/workflows/cloud-release.yml@refs/heads/main'`]);
  // Environmentの保護・workflow本体が完成するまではSAへのWIF bindingを作らない。
  return {
    schema_version: 1, status: 'preparation_only', environment, projects: [projectId, secretProject], region,
    operator_account: config.operator_account, accounts, workload_identity_provider: provider, audience,
    approval_required: true, estimated_budget_usd: config.budget_usd,
    budget_is_hard_limit: false, cleanup_after_days: config.cleanup_after_days,
    paid_resources: ['Artifact Registry の保存・転送', 'Cloud NAT の稼働・転送と外部 IPv4',
      'Firestore の保存・読み書き', 'Secret Manager の version・操作', 'Cloud Run の CPU・メモリ・通信',
      'Cloud Scheduler の job', '監査ログの保存'],
    commands,
    remaining: ['実project/番号・請求先・region quota・IAM条件のpreflightと費用承認',
      'Shared Secretの別途作成と固定version検証（payloadはこの計画に含めない）',
      'Service/Job/Schedulerの構築、digest promotion、設定version固定、WIF release/rollback',
      'staging IAM否定・ネットワークcanary・Run Secret expiry/delete・実Task retry・rollbackの実測'],
  };
}

export function planHash(plan) {
  return createHash('sha256').update(JSON.stringify(plan)).digest('hex');
}
