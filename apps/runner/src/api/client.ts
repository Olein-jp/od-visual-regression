import { createHash, randomBytes } from 'node:crypto';
import { setTimeout as wait } from 'node:timers/promises';
import { validateContract } from '@odvr/schemas';
import type { CompleteRequest, ProgressRequest, RunManifest, RunnerCredentials, RunState, SnapshotResult, SnapshotUploadResponse } from '@odvr/shared';
import { PinnedHttpClient, type PinnedResponse } from '../security/pinned-http-client.js';
import { DestinationPolicy, type DestinationPolicyOptions, type Purpose } from '../security/destination-policy.js';
import { SnapshotError } from '../errors.js';
import { decodeImage } from '../visual/normalize-image.js';

export type ProductErrorCode = NonNullable<SnapshotResult['error_code']>;
export const PRODUCT_MESSAGES: Readonly<Record<ProductErrorCode,string>> = Object.freeze({
  SNAPSHOT_FAILED:'一部の撮影結果を取得できませんでした。', NAVIGATION_FAILED:'ページ遷移に失敗しました。', HTTP_ERROR:'HTTP応答に失敗しました。',
  CAPTURE_FAILED:'撮影に失敗しました。', CONTEXT_CLOSE_FAILED:'Browser Contextの終了に失敗しました。', RUN_ABORTED:'Runの実行を中止しました。',
  RUN_DEADLINE_EXCEEDED:'Runの実行期限を超えました。', DISPATCH_TIMEOUT:'Runの起動を確認できませんでした。',
});
export class WordPressApiError extends Error {
  constructor(readonly code:string, readonly status:number|null, readonly retryable=false, readonly reason?:'missing'|'corrupt'|'incompatible') {
    super('WordPress APIの応答または接続を確認してください。');
  }
}
export interface WordPressClientOptions {
  callbackBase:string;
  runUuid:string;
  executionId:string;
  token:string;
  tokenExpiresAt:number;
  profile?:'cloud'|'local';
  localDestination?:DestinationPolicyOptions['localDestination'];
  transport?:PinnedHttpClient;
}
export interface SnapshotImages { image?:Buffer; diff_image?:Buffer }
/** Bufferとboundaryを一度だけ作り、応答喪失後も同じ内容を送る。 */
export function snapshotMultipart(result:SnapshotResult, images:SnapshotImages):{body:Buffer;contentType:string} {
  validateContract('snapshot-result',result);
  const expected = result.status === 'ERROR' ? [] : ['UNCHANGED','REVIEW','CHANGED'].includes(result.status) ? ['image','diff_image'] : ['image'];
  if (expected.some(key => !Object.hasOwn(images,key)) || Object.keys(images).some(key => !expected.includes(key))) throw new WordPressApiError('odvr_invalid_payload',400);
  const boundary = `odvr-${randomBytes(24).toString('hex')}`;
  const chunks:Buffer[] = [];
  for (const [name,value] of Object.entries(result)) {
    let scalar:string;
    if (name === 'diff_ratio' && value !== null) scalar = Number(value).toFixed(15).replace(/0+$/,'').replace(/\.$/,'');
    else scalar = typeof value === 'string' ? value : JSON.stringify(value);
    chunks.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${name}"\r\n\r\n${scalar}\r\n`));
  }
  for (const [name,png] of Object.entries(images)) {
    if (!Buffer.isBuffer(png) || png.length > 20*1024*1024) throw new WordPressApiError('odvr_invalid_payload',400);
    chunks.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${name}"; filename="snapshot.png"\r\nContent-Type: image/png\r\n\r\n`),Buffer.from(png),Buffer.from('\r\n'));
  }
  chunks.push(Buffer.from(`--${boundary}--\r\n`));
  const body = Buffer.concat(chunks);
  if (body.length > 42*1024*1024) throw new WordPressApiError('odvr_payload_too_large',413);
  return {body,contentType:`multipart/form-data; boundary=${boundary}`};
}
/** 登録された制御Originと固定経路だけへ、Bearerを所有して接続する。 */
export class WordPressClient {
  #token:string;
  #transport:PinnedHttpClient;
  #base:string;
  #uuid:string;
  #execution:string;
  #developmentOnly:boolean;
  #manifest?:RunManifest;
  #deadline:number;
  #abort = new AbortController();
  #timer:NodeJS.Timeout;
  constructor(options:WordPressClientOptions) {
    if (!/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(options.runUuid) || !/^[A-Za-z0-9_-]{43}$/.test(options.token) || !options.executionId || options.executionId.length>200 || /[\x00-\x1f\x7f]/.test(options.executionId) || !Number.isFinite(options.tokenExpiresAt) || options.tokenExpiresAt<=Date.now()) throw new WordPressApiError('odvr_invalid_payload',400);
    const policy = new DestinationPolicy({controlBase:options.callbackBase,captureOrigins:options.profile === 'local' && options.localDestination ? [options.localDestination.origin] : [],profile:options.profile,localDestination:options.localDestination});
    this.#base = policy.controlBase!;
    this.#uuid = options.runUuid;
    this.#execution = options.executionId;
    this.#token = options.token;
    this.#developmentOnly = options.profile === 'local';
    this.#deadline = Math.min(options.tokenExpiresAt,Date.now()+5400000);
    this.#transport = options.transport ?? new PinnedHttpClient({policy,runDeadline:this.#deadline});
    this.#timer = setTimeout(() => this.close(),this.#deadline-Date.now()); this.#timer.unref();
  }
  get deadline():number { return this.#deadline; }
  get signal():AbortSignal { return this.#abort.signal; }
  close():void { clearTimeout(this.#timer); this.#abort.abort(); this.#transport.close(); this.#token=''; }
  #header(response:PinnedResponse,name:string):string|undefined {
    const values=response.headers.filter(([key]) => key.toLowerCase()===name).map(([,value])=>value);
    if(values.length>1) throw new WordPressApiError('odvr_invalid_response',null);
    return values[0];
  }
  async #request(purpose:Exclude<Purpose,'capture'>,path:string,body?:Buffer,contentType='application/json'):Promise<PinnedResponse> {
    for(let attempt=0;attempt<3;attempt++) {
      if(this.#abort.signal.aborted || Date.now()>=this.#deadline) throw new WordPressApiError('odvr_run_deadline',null);
      try {
        const response = await this.#transport.request({url:this.#base+path,purpose,method:body ? 'POST':'GET',body,headers:[['X-ODVR-Execution-ID',this.#execution],...(body ? [['Content-Type',contentType] as const] : [])],auth:{kind:'bearer',token:this.#token,developmentOnly:this.#developmentOnly},signal:this.#abort.signal});
        if(response.status>=200 && response.status<300) return response;
        let payload:{code:string;data:{status:number;retryable:boolean;reason?:'missing'|'corrupt'|'incompatible'}}|undefined;
        try { payload=validateContract('error',JSON.parse(response.body.toString('utf8'))); if(payload?.data.status!==response.status) payload=undefined; } catch { /* 例外・応答本文はログへ出さない。 */ }
        const retryable = [429,500,502,503,504].includes(response.status) && (payload?.data.retryable ?? true);
        throw new WordPressApiError(payload?.code ?? 'odvr_api_unavailable',response.status,retryable,payload?.data.reason);
      } catch(error) {
        const failure = error instanceof WordPressApiError ? error : new WordPressApiError('odvr_api_connection_failed',null,error instanceof SnapshotError && ['DNS_ERROR','NETWORK_ERROR','NETWORK_TIMEOUT'].includes(error.code));
        if(!failure.retryable || attempt===2) throw failure;
        await wait((attempt===0 ? 1000:3000)+Math.floor(Math.random()*250),undefined,{signal:this.#abort.signal}).catch(()=>{throw new WordPressApiError('odvr_run_deadline',null);});
      }
    }
    throw new WordPressApiError('odvr_api_unavailable',null,true);
  }
  #json<T>(response:PinnedResponse,name:string):T {
    try {
      if(!this.#header(response,'content-type')?.toLowerCase().startsWith('application/json')) throw new Error();
      return validateContract<T>(name,JSON.parse(response.body.toString('utf8')));
    } catch { throw new WordPressApiError('odvr_invalid_response',response.status,true); }
  }
  async manifest():Promise<RunManifest> {
    const value=this.#json<RunManifest>(await this.#request('manifest',`/runs/${this.#uuid}/manifest`),'run-manifest');
    if(value.run.uuid!==this.#uuid || value.run.runner_execution_id!==this.#execution || value.run.status!=='running') throw new WordPressApiError('odvr_run_conflict',409);
    this.#deadline=Math.min(this.#deadline,Date.parse(value.run.deadline_at),Date.parse(value.run.created_at)+7200000);
    if(this.#deadline<=Date.now()) { this.close(); throw new WordPressApiError('odvr_run_deadline',null); }
    clearTimeout(this.#timer);this.#timer=setTimeout(()=>this.close(),this.#deadline-Date.now());this.#timer.unref();
    this.#manifest=value;
    return value;
  }
  async credentials():Promise<RunnerCredentials> {
    if(!this.#manifest) throw new WordPressApiError('odvr_manifest_required',409);
    const value=this.#json<RunnerCredentials>(await this.#request('credentials',`/runs/${this.#uuid}/credentials`),'runner-credentials');
    if(value.http_auth && !this.#manifest.allowed_origins.includes(value.http_auth.origin)) throw new WordPressApiError('odvr_invalid_credentials',400);
    return value;
  }
  async baseline(snapshotId:number):Promise<{image:Buffer}|{reason:'missing'|'corrupt'|'incompatible'}> {
    if(!this.#manifest?.reference.snapshots.some(item=>item.baseline_snapshot_id===snapshotId)) throw new WordPressApiError('odvr_not_found',404);
    try {
      const response=await this.#request('baseline',`/snapshots/${snapshotId}/baseline`);
      const sha=this.#header(response,'x-odvr-image-sha256');
      if(this.#header(response,'content-type')?.toLowerCase()!=='image/png' || !sha || !/^[a-f0-9]{64}$/.test(sha) || createHash('sha256').update(response.body).digest('hex')!==sha || this.#header(response,'content-length')!==String(response.body.length)) throw new WordPressApiError('odvr_invalid_baseline',200);
      try { const png=decodeImage(response.body);if(png.width>16384 || png.height>16384) throw new Error(); } catch { throw new WordPressApiError('odvr_invalid_baseline',200); }
      return {image:response.body};
    } catch(error) {
      if(error instanceof WordPressApiError && error.status===404 && error.code==='odvr_baseline_unavailable' && error.reason && ['missing','corrupt','incompatible'].includes(error.reason)) return {reason:error.reason};
      throw error;
    }
  }
  async upload(result:SnapshotResult,images:SnapshotImages):Promise<SnapshotUploadResponse> {
    const state=this.#manifest?.run.snapshot_states.find(item=>item.target_id===result.target_id && item.device_id===result.device_id);
    if(!state) throw new WordPressApiError('odvr_not_found',404);
    validateContract('snapshot-result',result,{settings:this.#manifest!.settings,reference_run_id:this.#manifest!.reference.run_id});
    const {body,contentType}=snapshotMultipart(result,images);
    const value=this.#json<SnapshotUploadResponse>(await this.#request('upload',`/runs/${this.#uuid}/snapshots`,body,contentType),'snapshot-upload-response');
    if(value.snapshot_id!==state.snapshot_id || value.status!==result.status) throw new WordPressApiError('odvr_snapshot_conflict',409);
    return value;
  }
  async progress(input:ProgressRequest):Promise<RunState> {
    validateContract('progress-request',input);
    if(input.runner_execution_id!==this.#execution) throw new WordPressApiError('odvr_run_conflict',409);
    const value=this.#state(await this.#request('progress',`/runs/${this.#uuid}/progress`,Buffer.from(JSON.stringify(input))));
    if(value.status!=='running') throw new WordPressApiError('odvr_run_conflict',409);
    return value;
  }
  async complete(input:CompleteRequest):Promise<RunState> {
    validateContract('complete-request',input);
    if(input.runner_execution_id!==this.#execution) throw new WordPressApiError('odvr_run_conflict',409);
    const value=this.#state(await this.#request('complete',`/runs/${this.#uuid}/complete`,Buffer.from(JSON.stringify(input))));
    if(!['complete','partial','failed'].includes(value.status) || (input.outcome==='failed' && value.status!=='failed')) throw new WordPressApiError('odvr_run_conflict',409);
    return value;
  }
  #state(response:PinnedResponse):RunState {
    const value=this.#json<RunState>(response,'run-state');
    if(value.run_uuid!==this.#uuid) throw new WordPressApiError('odvr_run_conflict',409);
    return value;
  }
}
