import { readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { foundationPlan, assertSeparateEnvironments, planHash } from '../cloud/plan.mjs';

export async function main(args) {
  if (args.length < 1 || args.length > 2) throw new Error('環境設定ファイルと、任意で別環境の設定ファイルを指定してください。');
  const current = JSON.parse(await readFile(args[0], 'utf8'));
  if (args[1]) assertSeparateEnvironments(current, JSON.parse(await readFile(args[1], 'utf8')));
  const plan = foundationPlan(current);
  process.stdout.write(JSON.stringify({ ...plan, plan_sha256: planHash(plan) }, null, 2) + '\n');
}

// この入口は計画の出力だけを行い、gcloud・認証・クラウド資源の作成を実行しない。
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  main(process.argv.slice(2)).catch(() => {
    process.stderr.write('クラウド計画を生成できません。非秘密の環境設定を確認してください。\n');
    process.exitCode = 1;
  });
}
