import { readFile } from 'node:fs/promises';
import { parseManifest } from './config.js';
import { classifyError } from './errors.js';
import { executeRun } from './jobs/execute-run.js';
try {
  const [manifestPath, output, baseline, ...extra] = process.argv.slice(2);
  if (!manifestPath || !output || extra.length) throw new Error('使い方: npm run runner -- manifest.json 新規出力ディレクトリ [baselineディレクトリ]');
  const manifest = parseManifest(JSON.parse(await readFile(manifestPath, 'utf8')));
  const report = await executeRun(manifest, output, baseline);
  console.log(`${report.total_snapshots}件完了、エラー${report.error_snapshots}件`);
  if (report.error_snapshots) process.exitCode = 1;
} catch (error) {
  const failure = classifyError(error, 'SNAPSHOT_FAILED');
  console.error(`${failure.code}: ${failure.message}`);
  process.exitCode = 1;
}
