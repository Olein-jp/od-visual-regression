import { readFile } from 'node:fs/promises';
import { deploymentPlan } from '../cloud/deployment.mjs';
import { planHash } from '../cloud/plan.mjs';

try {
  const args = process.argv.slice(2);
  if (args.length !== 2) throw new Error();
  const environment = JSON.parse(await readFile(args[0], 'utf8'));
  const release = JSON.parse(await readFile(args[1], 'utf8'));
  const plan = deploymentPlan(environment, release);
  process.stdout.write(JSON.stringify({ ...plan, plan_sha256: planHash(plan) }, null, 2) + '\n');
} catch {
  process.stderr.write('固定配備計画を生成できません。非秘密の設定を確認してください。\n');
  process.exitCode = 1;
}
