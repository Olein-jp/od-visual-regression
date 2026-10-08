import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, mkdtemp, rm, mkdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { PNG } from 'pngjs';
import { executeProductRun } from '../dist/jobs/product-run.js';
import { WordPressApiError, PRODUCT_MESSAGES } from '../dist/api/client.js';
import { SnapshotError } from '../dist/errors.js';
const fixtures=JSON.parse(await readFile(new URL('../../../wordpress/od-visual-regression/tests/contract-fixtures.json',import.meta.url),'utf8'));
export const fixture=name=>structuredClone(fixtures.find(item=>item.valid && item.schema===name).value);
const versions={runner:'0.1.0',playwright:'fixture',chromium:'fixture'};
const png=PNG.sync.write(new PNG({width:1,height:1}));
function setup(t,{pending=2,reference=null,launchFailure=false,closeFailure=false,captureFailure=false,uploadFailure=false,preflight=false}={}) {
  const manifest=fixture('run-manifest');manifest.run.runner_execution_id='execution';manifest.reference.run_id=reference;
  manifest.run.created_at=new Date().toISOString();manifest.run.deadline_at=new Date(Date.now()+60000).toISOString();
  manifest.targets=[1,2,3].map(id=>({...manifest.targets[0],id,url:'https://capture.test/?private=hidden'}));
  manifest.allowed_origins=['https://capture.test'];
  manifest.run.snapshot_states=manifest.targets.map((item,i)=>({snapshot_id:item.id,target_id:item.id,device_id:1,status:i<pending ? 'PENDING':'CAPTURED'}));
  manifest.reference.snapshots=manifest.targets.map(item=>({target_id:item.id,device_id:1,baseline_snapshot_id:reference ? item.id:null,reason:reference ? null:'no_reference'}));
  manifest.reference.versions=reference ? versions:null;
  const seen={captures:[],uploads:[],complete:[],progress:0,closed:0,launch:0,basic:[],baselines:[]};const abort=new AbortController();
  const state=()=>({...fixture('run-state'),run_uuid:manifest.run.uuid,status:'complete',total_snapshots:3,completed_snapshots:3,error_snapshots:0,pending_snapshots:0,completed_at:new Date().toISOString()});
  const client={signal:abort.signal,deadline:Date.now()+60000,complete:async input=>{seen.complete.push(input);if(seen.complete.length===1 && !preflight)throw new WordPressApiError('odvr_run_incomplete',409);return {...state(),status:input.outcome==='failed' ? 'failed':'complete'};},manifest:async()=>manifest,progress:async()=>{seen.progress++;},credentials:async()=>({schema_version:1,http_auth:{origin:'https://capture.test',username:'dummy-user',password:'dummy-private-password'}}),baseline:async id=>{seen.baselines.push(id);return {image:png};},upload:async(result,images)=>{if(uploadFailure)throw new WordPressApiError('odvr_api_unavailable',503,true);seen.uploads.push({result,images});}};
  const dependencies={versions,launch:async()=>{seen.launch++;if(launchFailure)throw new Error('Bearer confidential');return {version:()=>versions.chromium,isConnected:()=>true,close:async()=>{seen.closed++;if(closeFailure)throw new Error('Bearer confidential');}};},capture:async(_browser,_manifest,pair,_transport,auth)=>{seen.captures.push(pair.target_id);seen.basic.push(auth);if(captureFailure && pair.target_id===1)throw new SnapshotError('HTTP_ERROR');return {image:png,httpStatus:200};}};
  t.after(()=>abort.abort());return {manifest,seen,client,dependencies,abort};
}
test('同Executionのpendingだけを撮影しBasicは撮影依存だけへ渡す',async t=>{
  const {seen,client,dependencies}=setup(t);const directory=await mkdtemp(join(tmpdir(),'odvr-product-'));t.after(()=>rm(directory,{recursive:true,force:true}));
  const reportPath=join(directory,'result.json');const state=await executeProductRun(client,{executionId:'execution',reportPath},dependencies);
  assert.equal(state.status,'complete');assert.deepEqual(seen.captures.sort(),[1,2]);assert.equal(seen.closed,1);assert.equal(seen.uploads.length,2);assert.equal(seen.progress,3);assert.ok(seen.basic.every(auth=>auth.kind==='basic' && auth.password==='dummy-private-password'));
  assert.ok(seen.uploads.every(item=>item.result.status==='CAPTURED' && item.images.image));
  const report=await readFile(reportPath,'utf8');for(const secret of ['dummy-user','dummy-private-password','private=hidden','Bearer','callback','baseline_snapshot'])assert.ok(!report.includes(secret));
});
test('対象HTTP失敗をERRORにして残りの撮影を続行する',async t=>{const {seen,client,dependencies}=setup(t,{captureFailure:true});await executeProductRun(client,{executionId:'execution'},dependencies);assert.deepEqual(seen.captures.sort(),[1,2]);const failed=seen.uploads.find(item=>item.result.target_id===1);assert.equal(failed.result.error_code,'HTTP_ERROR');assert.equal(failed.result.error_message,PRODUCT_MESSAGES.HTTP_ERROR);assert.deepEqual(failed.images,{});assert.equal(seen.complete.at(-1).outcome,'finished');});
test('固定Baselineのみ取得し比較status・diffを送信する',async t=>{const {seen,client,dependencies}=setup(t,{reference:9});await executeProductRun(client,{executionId:'execution'},dependencies);assert.deepEqual(seen.baselines.sort(),[1,2]);assert.ok(seen.uploads.every(item=>item.result.status==='UNCHANGED' && item.result.diff_ratio===0 && item.images.diff_image));});
test('製品の欠損・新Target・Version非互換は理由付きNO_BASELINEになる',async t=>{
  for(const reason of ['missing','corrupt','incompatible','new_target','new_device']) {
    const {manifest,seen,client,dependencies}=setup(t,{reference:9});
    if(['new_target','new_device'].includes(reason)){manifest.reference.snapshots.forEach(item=>{item.reason=reason;item.baseline_snapshot_id=null;});}
    else if(reason==='incompatible')manifest.reference.versions={...versions,chromium:'old'};
    else client.baseline=async()=>({reason});
    await executeProductRun(client,{executionId:'execution'},dependencies);assert.ok(seen.uploads.every(item=>item.result.status==='NO_BASELINE' && item.result.no_baseline_reason===reason && item.images.image && !item.images.diff_image));
  }
});
test('PNG検証失敗はNO_BASELINEに変換せず対象ERRORにする',async t=>{const {seen,client,dependencies}=setup(t,{reference:9});client.baseline=async()=>{throw new WordPressApiError('odvr_invalid_baseline',200);};await executeProductRun(client,{executionId:'execution'},dependencies);assert.ok(seen.uploads.every(item=>item.result.status==='ERROR' && item.result.no_baseline_reason===null));});
test('Browser起動・cleanup・結果保存の失敗はComplete failedへ反映する',async t=>{
  for(const kind of ['launch','close','save']){const {seen,client,dependencies}=setup(t,{launchFailure:kind==='launch',closeFailure:kind==='close'});const directory=await mkdtemp(join(tmpdir(),'odvr-product-'));t.after(()=>rm(directory,{recursive:true,force:true}));const path=join(directory,'result.json');if(kind==='save')await mkdir(path);
    const state=await executeProductRun(client,{executionId:'execution',reportPath:path},dependencies);assert.equal(state.status,'failed');assert.equal(seen.complete.at(-1).outcome,'failed');assert.equal(seen.complete.at(-1).error_code,'RUN_ABORTED');assert.ok(!JSON.stringify(seen.complete).includes('confidential'));
  }
});
test('回復可能なUpload障害はComplete failedを確定せずTask retryへ渡す',async t=>{const {seen,client,dependencies}=setup(t,{uploadFailure:true});await assert.rejects(executeProductRun(client,{executionId:'execution'},dependencies),error=>error.retryable===true);assert.equal(seen.complete.length,1);assert.ok(seen.closed>=1);});
test('Complete確定後のTask retryは同じCompleteだけで終了する',async t=>{const {seen,client,dependencies}=setup(t,{preflight:true});client.manifest=async()=>{throw new Error('呼び出してはいけない');};await executeProductRun(client,{executionId:'execution'},dependencies);assert.equal(seen.complete.length,1);assert.equal(seen.launch,0);assert.equal(seen.progress,0);});
test('401/403/404・期限後はManifest探索や別経路の送信をしない',async t=>{for(const status of [401,403,404]){const {seen,client,dependencies}=setup(t);client.complete=async()=>{throw new WordPressApiError('odvr_runner_unauthorized',status);};await assert.rejects(executeProductRun(client,{executionId:'execution'},dependencies),error=>error.status===status);assert.equal(seen.launch,0);}const {seen,client,dependencies,abort}=setup(t);abort.abort();await assert.rejects(executeProductRun(client,{executionId:'execution'},dependencies),{code:'odvr_run_deadline'});assert.equal(seen.uploads.length,0);});

test('Browser切断は対象失敗へ分散せずRun全体をfailedにする',async t=>{const {seen,client,dependencies}=setup(t);const launch=dependencies.launch;dependencies.launch=async()=>({...await launch(),isConnected:()=>false});const state=await executeProductRun(client,{executionId:'execution'},dependencies);assert.equal(state.status,'failed');assert.equal(seen.uploads.length,0);assert.equal(seen.complete.at(-1).outcome,'failed');});
