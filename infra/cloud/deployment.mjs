import { checkedEnvironment } from './plan.mjs';
import { profiles } from './usage-policy.mjs';

/** 承認用の固定配備計画。認証・クラウド操作は実行しない。 */
export function deploymentPlan(environment, release) {
  const config = checkedEnvironment(environment);
  const expected = ['component', 'git_revision', 'image', 'config_version'];
  const fail = () => { throw new Error('固定配備の設定を確認してください。'); };
  if (!release || Object.keys(release).length !== expected.length
    || !expected.every(key => Object.hasOwn(release, key))
    || !['runner', 'dispatcher'].includes(release.component)
    || typeof release.git_revision !== 'string' || !/^[a-f0-9]{40}$/.test(release.git_revision)
    || typeof release.config_version !== 'string' || !/^[1-9][0-9]{0,9}$/.test(release.config_version)) fail();
  const { component } = release;
  const profile = profiles[config.usage_policy.profile];
  const imagePrefix = `ghcr.io/olein-jp/odvr-${component}@sha256:`;
  if (typeof release.image !== 'string' || !release.image.startsWith(imagePrefix)
    || !/^[a-f0-9]{64}$/.test(release.image.slice(imagePrefix.length))) fail();
  const prefix = `odvr-${config.environment}`;
  const common = [`--region=${config.region}`, '--execution-environment=gen2',
    `--service-account=odvr-${component}@${config.project_id}.iam.gserviceaccount.com`,
    `--labels=odvr-environment=${config.environment},odvr-revision=${release.git_revision}`];
  const args = component === 'runner'
    ? ['run', 'jobs', 'deploy', 'odvr-runner', ...common,
      `--network=${prefix}`, `--subnet=${prefix}`, `--network-tags=${prefix}`, '--vpc-egress=all-traffic',
      '--tasks=1', '--parallelism=1',
      `--task-timeout=${profile.task_seconds}s`, `--max-retries=${profile.retries}`, '--container=runner', '--cpu=2', '--memory=2Gi',
      '--command=node,apps/runner/dist/job.js', '--args=']
    : ['run', 'deploy', 'odvr-dispatcher', ...common, '--clear-vpc-connector', '--clear-network', '--no-allow-unauthenticated', '--invoker-iam-check',
      '--ingress=all', '--min=0', '--max=1', '--min-instances=0', '--max-instances=1',
      '--timeout=30s', '--concurrency=1', '--cpu-throttling', '--no-cpu-boost',
      '--container=dispatcher', '--cpu=1', '--memory=512Mi', '--port=8080',
      '--command=node,apps/dispatcher/dist/index.js', '--args='];
  args.push(`--image=${release.image}`, '--set-env-vars=NODE_ENV=production',
    `--set-secrets=/etc/odvr/${component}.json=odvr-${component}-config:${release.config_version}`);
  // container固有フラグの後に認証/対象指定だけを付ける。
  args.push(`--configuration=${config.configuration}`, `--account=${config.operator_account}`, `--project=${config.project_id}`);
  return {
    schema_version: 2, status: 'preparation_only', approval_required: true,
    environment: config.environment, project: config.project_id, region: config.region,
    component, git_revision: release.git_revision, image: release.image, config_version: release.config_version,
    argv: ['gcloud', ...args],
    prerequisites: ['費用と対象の承認', '一時NAT controller/利用予約/Runner予算実装の検証完了（未完了なら配備・公開を拒否）', 'Registry manifest digestと検証済みGit revisionの対応確認',
      '非秘密configの固定versionとcloud profile検査', '既存定義・コンテナ数・IAM・実行中Runの事前照合'],
    after_deploy: component === 'runner'
      ? ['実Job定義とgenerationの取得・照合', '新generationをDispatcher configへ同期', '再実行はしない']
      : ['private ServiceのIAM・traffic・実定義照合', 'Schedulerは停止を維持', 'canary合格前に公開しない'],
  };
}

export function runnerRegistration(environment) {
  const config = checkedEnvironment(environment);
  return { schema_version: 1, profile: 'cloud', usage_profile: config.usage_policy.profile, secret_project: config.run_secret_project_number,
    sites: Object.fromEntries(Object.entries(config.sites).map(([id, site]) => [id, { callback_base: site.callback_base }])) };
}
