import { readFile, open, rename, rm } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { chromium, type Browser } from 'playwright';
import { CLOUD_CAPTURE_PROFILES, type CloudCaptureProfile } from '@odvr/shared';
import type { CompleteRequest, NetworkDiagnostics, RunManifest, RunState, SnapshotResult } from '@odvr/shared';
import { WordPressClient, WordPressApiError, PRODUCT_MESSAGES, type SnapshotImages, type WordPressClientOptions } from '../api/client.js';
import { createContext, BROWSER_LAUNCH_OPTIONS } from '../browser/context-factory.js';
import { capturePage } from '../browser/screenshot.js';
import { installNetworkGuard } from '../security/network-guard.js';
import { DestinationPolicy } from '../security/destination-policy.js';
import { PinnedHttpClient, type TransportAuth } from '../security/pinned-http-client.js';
import { SnapshotError } from '../errors.js';
import { decodeImage } from '../visual/normalize-image.js';
import { compareImages } from '../visual/compare.js';
import { productVersionsCompatible } from './baseline.js';

export async function installedVersions():Promise<CompleteRequest['versions']> {
  const require=createRequire(import.meta.url);
  const browsers=JSON.parse(await readFile(join(dirname(require.resolve('playwright-core/package.json')),'browsers.json'),'utf8'));
  const version=browsers.browsers.find((item:{name:string})=>item.name==='chromium')?.browserVersion;
  if(typeof version!=='string' || !version) throw new Error('Chromiumの固定Versionを確認してください。');
  return {runner:require('../../package.json').version,playwright:require('playwright/package.json').version,chromium:version};
}
type Pair=RunManifest['run']['snapshot_states'][number];
type Captured={image:Buffer;httpStatus:number};
export interface ProductRunOptions {
  executionId:string;
  profile?:'cloud'|'local';
  usageProfile?:CloudCaptureProfile;
  localDestination?:WordPressClientOptions['localDestination'];
  /** 登録済み運用設定の出力先だけを渡す。画像・Manifest・秘密は保存しない。 */
  reportPath?:string;
}
/** 固定依存の障害試験用。Job入力から差し替えることはできない。 */
export interface ProductRunDependencies {
  versions?:CompleteRequest['versions'];
  launch?:()=>Promise<Browser>;
  capture?:(browser:Browser,manifest:RunManifest,pair:Pair,transport:PinnedHttpClient,auth?:TransportAuth)=>Promise<Captured>;
  transport?:(policy:DestinationPolicy,deadline:number)=>PinnedHttpClient;
}
async function capture(browser:Browser,manifest:RunManifest,pair:Pair,transport:PinnedHttpClient,auth?:TransportAuth):Promise<Captured> {
  const target=manifest.targets.find(item=>item.id===pair.target_id)!;
  const device=manifest.devices.find(item=>item.id===pair.device_id)!;
  const context=await createContext(browser,device);
  let failure:unknown;
  let result:Captured|undefined;
  let diagnostics:NetworkDiagnostics|undefined;
  try {
    diagnostics=await installNetworkGuard(context,manifest.allowed_origins,{client:transport,targetKey:`${pair.target_id}:${pair.device_id}`,auth});
    const page=await context.newPage();let crashed=false;
    page.on('crash',()=>{crashed=true;});
    let response;
    try { response=await page.goto(target.url,{waitUntil:'domcontentloaded',timeout:manifest.settings.navigation_timeout_ms}); }
    catch { throw new SnapshotError('NAVIGATION_TIMEOUT'); }
    if(!response || response.status()>=400) throw new SnapshotError('HTTP_ERROR');
    const image=await capturePage(page,manifest.settings);
    if(crashed || diagnostics.blocked_resource_count) throw new SnapshotError('RESOURCE_BLOCKED');
    result={image,httpStatus:response.status()};
  } catch(error) { failure=error; }
  try { await context.close(); } catch { failure ??= new SnapshotError('CONTEXT_CLOSE_FAILED'); }
  if(diagnostics?.blocked_resource_count) failure ??= new SnapshotError('RESOURCE_BLOCKED');
  if(failure) throw failure;
  return result!;
}
function emptyResult(pair:Pair):SnapshotResult {
  return {schema_version:1,target_id:pair.target_id,device_id:pair.device_id,status:'ERROR',width:null,height:null,baseline_width:null,baseline_height:null,dimension_changed:false,diff_pixels:null,total_pixels:null,diff_ratio:null,duration_ms:0,http_status:null,error_code:null,error_message:null,no_baseline_reason:null};
}
async function saveReport(path:string,state:object):Promise<void> {
  const temporary=path+'.tmp';
  let owned=false;
  try {
    const file=await open(temporary,'wx',0o600);owned=true;
    try { await file.writeFile(JSON.stringify(state)+'\n'); } finally { await file.close(); }
    await rename(temporary,path);
  }
  catch { if(owned) await rm(temporary,{force:true}).catch(()=>{});throw new Error('結果保存に失敗しました。'); }
}
/** 同Executionのpendingのみを撮影し、WordPressの集計を最終状態とする。 */
export async function executeProductRun(client:WordPressClient,options:ProductRunOptions,dependencies:ProductRunDependencies={}):Promise<RunState> {
  if(client.signal.aborted) throw new WordPressApiError('odvr_run_deadline',null);
  const versions=dependencies.versions ?? await installedVersions();
  const finished:CompleteRequest={schema_version:1,runner_execution_id:options.executionId,versions,outcome:'finished',error_code:null,error_message:null};
  // Completeの応答喪失後は同じ要求だけを再送し、終端RunへManifestを要求しない。
  try { return await client.complete(finished); }
  catch(error) { if(!(error instanceof WordPressApiError) || error.status!==409 || !['odvr_run_incomplete','odvr_run_conflict','odvr_run_closed'].includes(error.code)) throw error; }
  const manifest=await client.manifest();
  const limits=options.usageProfile && Object.hasOwn(CLOUD_CAPTURE_PROFILES,options.usageProfile) ? CLOUD_CAPTURE_PROFILES[options.usageProfile]:undefined;
  if(options.profile==='cloud' && !limits) throw new WordPressApiError('odvr_usage_unavailable',503);
  if(limits && (manifest.targets.length>limits.targets || manifest.devices.length>limits.devices
    || manifest.run.snapshot_states.length>limits.targets*limits.devices)) {
    return client.complete({...finished,outcome:'failed',error_code:'RUN_ABORTED',error_message:PRODUCT_MESSAGES.RUN_ABORTED});
  }
  await client.progress({schema_version:1,runner_execution_id:options.executionId,versions});
  const credentials=await client.credentials();
  let auth:TransportAuth|undefined=credentials.http_auth ? {kind:'basic',...credentials.http_auth,developmentOnly:options.profile==='local'} : undefined;
  credentials.http_auth=null;
  let browser:Browser|undefined;
  let transport:PinnedHttpClient|undefined;
  let fatal:unknown;
  const results:SnapshotResult[]=[];
  const queue=manifest.run.snapshot_states.filter(item=>item.status==='PENDING');
  const stop=()=>{transport?.close();void browser?.close().catch(()=>{});};
  client.signal.addEventListener('abort',stop,{once:true});
  try {
    if(queue.length) {
      browser=await (dependencies.launch ?? (()=>chromium.launch(BROWSER_LAUNCH_OPTIONS)))();
      if(browser.version()!==versions.chromium) throw new Error('ChromiumのVersionが一致しません。');
      if(client.signal.aborted) throw new WordPressApiError('odvr_run_deadline',null);
      const policy=new DestinationPolicy({captureOrigins:manifest.allowed_origins,profile:options.profile,localDestination:options.localDestination});
      // 制御側4接続/256MiBと合わせて既存のRun全体上限に収める。
      transport=dependencies.transport ? dependencies.transport(policy,client.deadline) : new PinnedHttpClient({policy,runDeadline:client.deadline,limits:{runConnections:12,runBytes:limits?.captureBytes ?? 768*1024*1024}});
      const workers=await Promise.allSettled(Array.from({length:Math.min(manifest.settings.concurrency,limits?.concurrency ?? manifest.settings.concurrency)},async()=>{
        while(!fatal && !client.signal.aborted) {
          if(!browser!.isConnected()) { fatal=new Error('Browserの接続が終了しました。');stop();return; }
          const pair=queue.shift();if(!pair) return;
          const begin=Date.now();let result=emptyResult(pair);let images:SnapshotImages={};
          try {
            const current=await (dependencies.capture ?? capture)(browser!,manifest,pair,transport!,auth);
            const decoded=decodeImage(current.image);
            if(current.image.length>20*1024*1024 || decoded.width>16384 || decoded.height>16384) throw new Error();
            result={...result,status:'CAPTURED',width:decoded.width,height:decoded.height,http_status:current.httpStatus};images={image:current.image};
            if(manifest.reference.run_id!==null) {
              const reference=manifest.reference.snapshots.find(item=>item.target_id===pair.target_id && item.device_id===pair.device_id)!;
              let reason=reference.reason;
              if(reference.baseline_snapshot_id!==null && !productVersionsCompatible(manifest.reference.versions,versions)) reason='incompatible';
              if(reason===null && reference.baseline_snapshot_id!==null) {
                const baseline=await client.baseline(reference.baseline_snapshot_id);
                if('reason' in baseline) reason=baseline.reason;
                else {
                  const {diff_image,...comparison}=compareImages(baseline.image,current.image,manifest.settings);
                  if(diff_image.length>20*1024*1024) throw new Error();
                  result={...result,...comparison};images.diff_image=diff_image;
                }
              }
              if(reason && reason!=='no_reference') result={...result,status:'NO_BASELINE',no_baseline_reason:reason};
            }
          } catch(error) {
            if(!browser!.isConnected()) { fatal=new Error('Browserの接続が終了しました。');stop();return; }
            if(error instanceof WordPressApiError && error.code!=='odvr_invalid_baseline') { fatal=error;stop();throw error; }
            const code=error instanceof SnapshotError && error.code==='HTTP_ERROR' ? 'HTTP_ERROR' : error instanceof SnapshotError && error.code==='NAVIGATION_TIMEOUT' ? 'NAVIGATION_FAILED' : error instanceof SnapshotError && error.code==='CONTEXT_CLOSE_FAILED' ? 'CONTEXT_CLOSE_FAILED' : 'CAPTURE_FAILED';
            result={...emptyResult(pair),error_code:code,error_message:PRODUCT_MESSAGES[code]};images={};
          }
          result.duration_ms=Date.now()-begin;
          if(client.signal.aborted || fatal) return;
          try { await client.upload(result,images);results.push(result);await client.progress({schema_version:1,runner_execution_id:options.executionId,versions}); }
          catch(error) { fatal=error;stop();throw error; }
        }
      }));
      const failed=workers.find(item=>item.status==='rejected');
      if(failed?.status==='rejected') throw failed.reason;
    }
    if(client.signal.aborted) throw new WordPressApiError('odvr_run_deadline',null);
  } catch(error) { fatal ??= error; }
  finally {
    client.signal.removeEventListener('abort',stop);auth=undefined;transport?.close();
    try { await browser?.close(); } catch(error) { fatal ??= error; }
  }
  // 回復可能な制御通信障害はTask retryに渡す。権限拒否・期限後は別経路を探さない。
  if(fatal instanceof WordPressApiError) throw fatal;
  if(options.reportPath) {
    try { await saveReport(options.reportPath,{run_uuid:manifest.run.uuid,runner_execution_id:options.executionId,versions,snapshots:results.sort((a,b)=>a.target_id-b.target_id || a.device_id-b.device_id),outcome:fatal ? 'failed':'finished'}); }
    catch(error) { fatal ??= error; }
  }
  return client.complete(fatal ? {...finished,outcome:'failed',error_code:'RUN_ABORTED',error_message:PRODUCT_MESSAGES.RUN_ABORTED} : finished);
}
