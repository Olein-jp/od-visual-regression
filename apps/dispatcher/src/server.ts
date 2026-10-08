import http from 'node:http';
import { randomUUID } from 'node:crypto';
import { validateContract } from '@odvr/schemas';
import type { DispatchRequest } from '@odvr/shared';
import { DispatchEngine } from './engine.js';
import { HmacVerifier,strictJSON } from './hmac.js';
import { DispatchError } from './types.js';
export interface ServerDependencies {engine:DispatchEngine;verifier:HmacVerifier;workerAuth:(headers:readonly string[])=>Promise<boolean>;probe:(site:string)=>Promise<boolean>;log?:(entry:{request_id:string;status:number;code:string})=>void;clock?:()=>number}
async function rawBody(request:http.IncomingMessage,post:boolean):Promise<Buffer>{
 const headers=request.rawHeaders;const single=(name:string)=>{const values=[];for(let i=0;i<headers.length;i+=2)if(headers[i].toLowerCase()===name)values.push(headers[i+1]);if(values.length>1)throw new DispatchError('odvr_invalid_payload',400);return values[0];};
 const encoding=single('content-encoding');if(encoding && encoding!=='identity')throw new DispatchError('odvr_invalid_payload',400);
 const length=single('content-length');if(length && (!/^(?:0|[1-9][0-9]*)$/.test(length) || Number(length)>16384))throw new DispatchError('odvr_payload_too_large',413);
 if(post && !/^application\/json(?:\s*;\s*charset=utf-8)?$/i.test(single('content-type') ?? ''))throw new DispatchError('odvr_invalid_payload',400);
 const chunks:Buffer[]=[];let size=0;
 for await(const chunk of request){size+=chunk.length;if(size>16384)throw new DispatchError('odvr_payload_too_large',413);chunks.push(Buffer.from(chunk));}
 if(!request.complete || (length && Number(length)!==size) || (!post && size!==0))throw new DispatchError('odvr_invalid_payload',400);
 return Buffer.concat(chunks,size);
}
/** 202の送信後は処理しない。未完了処理は台帳と認証済みworkerからだけ再開する。 */
export function createDispatcherServer(dependencies:ServerDependencies):http.Server {
 let active=0;const server=http.createServer({maxHeaderSize:8192,requestTimeout:15000,headersTimeout:10000,keepAliveTimeout:5000},async(request,response)=>{
  const requestId=randomUUID();let status=503;let code='odvr_dispatch_unavailable';let body:unknown;let counted=false;
  try{
   if(active>=8)throw new DispatchError('odvr_dispatch_busy',503,true);active++;counted=true;
   if(!request.url || request.url.length>4096 || !request.url.startsWith('/') || request.url.startsWith('//'))throw new DispatchError('odvr_invalid_payload',400);
   const url=new URL(request.url,'http://dispatcher.invalid');
   const worker=request.method==='POST' && url.pathname==='/internal/reconcile' && !url.search;
   if(worker){await rawBody(request,false);if(!await dependencies.workerAuth(request.rawHeaders))throw new DispatchError('odvr_dispatch_unauthorized',401);body=await dependencies.engine.worker();status=200;code='odvr_worker_complete';}
   else if(request.method==='POST' && ['/v1/jobs','/v1/connection-test'].includes(url.pathname) && !url.search){
    const raw=await rawBody(request,true);const input=strictJSON(raw);const auth=await dependencies.verifier.authenticate(input.site_id,raw,request.rawHeaders);const diagnosis=url.pathname==='/v1/connection-test';const payload=dependencies.verifier.payload(raw,auth.site,diagnosis);
    if(diagnosis){let passed=false;try{await dependencies.engine.dependencies.ledger.store.health();passed=await dependencies.engine.dependencies.jobs.verify() && await dependencies.probe(payload.site_id);}catch{/* 固定診断結果だけを返す。 */}
     body=validateContract('connection-test-response',{schema_version:1,item:{checks:{settings:'passed',storage:'passed',dispatcher:passed ? 'passed':'failed'},checked_at:new Date((dependencies.clock ?? Date.now)()).toISOString().replace(/\.\d{3}Z$/,'Z'),code:passed ? 'odvr_dispatch_ready':'odvr_dispatch_unavailable',message:passed ? 'Dispatcherとの接続を確認しました。':'Dispatcherの接続設定を確認してください。'}});status=200;code=passed ? 'odvr_dispatch_ready':'odvr_dispatch_unavailable';
    }else{const result=await dependencies.engine.accept(payload as DispatchRequest,raw,auth.signedAt);body=validateContract('dispatch-response',result.body);status=result.status;code='odvr_dispatch_accepted';}
   }else if(request.method==='GET' && /^\/v1\/jobs\/[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(url.pathname)){
    if([...url.searchParams.keys()].join()!=='site_id')throw new DispatchError('odvr_invalid_payload',400);const raw=await rawBody(request,false);const site=url.searchParams.get('site_id');await dependencies.verifier.authenticate(site,raw,request.rawHeaders);const result=await dependencies.engine.lookup(site!,url.pathname.slice('/v1/jobs/'.length));body=validateContract('dispatch-response',result.body);status=result.status;code='odvr_dispatch_accepted';
   }else throw new DispatchError('odvr_dispatch_not_found',404);
  }catch(error){const safe=error instanceof DispatchError ? error:new DispatchError('odvr_dispatch_unavailable',503,true);status=safe.status;code=safe.code;body={schema_version:1,code,message:safe.message,data:{status,retryable:safe.retryable,request_id:requestId}};if(safe.retryable)response.setHeader('Retry-After','3');}
  finally{if(counted)active--;}
  dependencies.log?.({request_id:requestId,status,code});if(!response.destroyed){response.writeHead(status,{'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store','X-Content-Type-Options':'nosniff'});response.end(JSON.stringify(body));}
 });server.maxRequestsPerSocket=100;server.setTimeout(60000,socket=>socket.destroy());return server;
}
