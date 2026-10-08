import { WordPressClient } from './api/client.js';
import { loadJob } from './jobs/job-config.js';
import { executeProductRun } from './jobs/product-run.js';
let client:WordPressClient|undefined;
try {
  if(process.argv.length!==2) throw new Error();
  const job=await loadJob();
  client=new WordPressClient(job.client);job.client.token='';
  const state=await executeProductRun(client,job.run);
  console.log(JSON.stringify({run_uuid:state.run_uuid,status:state.status,completed_snapshots:state.completed_snapshots,error_snapshots:state.error_snapshots}));
} catch {
  // SDK/HTTP/ファイル/ページ由来の例外原文と秘密参照を出力しない。
  console.error('ODVR_JOB_FAILED: Jobの設定・接続・実行状態を確認してください。');
  process.exitCode=1;
} finally { client?.close(); }
