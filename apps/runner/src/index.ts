import { readFile } from 'node:fs/promises';
import { parseManifest } from './config.js';
import { classifyError } from './errors.js';
import { executeRun, RunResultSaveError } from './jobs/execute-run.js';
try {
  const [manifestPath, output, baseline, ...extra] = process.argv.slice(2);
  if (!manifestPath || !output || extra.length) throw new Error('使い方: npm run runner -- manifest.json 新規出力ディレクトリ [baselineディレクトリ]');
  const manifest = parseManifest(JSON.parse(await readFile(manifestPath, 'utf8')));
  const report = await executeRun(manifest, output, baseline);
  console.log(`${report.total_snapshots}件完了、エラー${report.error_snapshots}件、未実行${report.unexecuted_snapshots}件`);
  if (report.run_error) console.error(`${report.run_error.error_code}: ${report.run_error.error_message}`);
  if (report.cleanup_error && report.cleanup_error !== report.run_error) console.error(`${report.cleanup_error.error_code}: ${report.cleanup_error.error_message}`);
  if (report.status === 'ERROR') process.exitCode = 1;
} catch (error) {
  if (error instanceof RunResultSaveError) {
    if (error.run_error) console.error(`${error.run_error.error_code}: ${error.run_error.error_message}`);
    if (error.cleanup_error && error.cleanup_error !== error.run_error) console.error(`${error.cleanup_error.error_code}: ${error.cleanup_error.error_message}`);
    console.error(`RESULT_SAVE_FAILED: ${error.message}`);
  } else {
    const failure = classifyError(error, 'SNAPSHOT_FAILED');
    console.error(`${failure.code}: ${failure.message}`);
  }
  process.exitCode = 1;
}
