import test from 'node:test';
import assert from 'node:assert/strict';
import https from 'node:https';
import { readFileSync } from 'node:fs';
import { networkInterfaces } from 'node:os';
import { createHash } from 'node:crypto';
import { PNG } from 'pngjs';
import { WordPressClient, snapshotMultipart } from '../dist/api/client.js';
import { PinnedHttpClient } from '../dist/security/pinned-http-client.js';
import { DestinationPolicy } from '../dist/security/destination-policy.js';
const fixtures=JSON.parse(readFileSync(new URL('../../../wordpress/od-visual-regression/tests/contract-fixtures.json',import.meta.url)));
const value=name=>structuredClone(fixtures.find(item=>item.valid && item.schema===name).value);
const address=Object.values(networkInterfaces()).flat().find(item=>item.family==='IPv4' && !item.internal && /^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/.test(item.address))?.address;
assert.ok(address,'実ソケット検証用のRFC1918接続先が必要です。');
const cert=readFileSync(new URL('./fixtures/tls/fixture-cert.pem',import.meta.url));
const key=readFileSync(new URL('./fixtures/tls/fixture-key.pem',import.meta.url));
const token='fixtureToken'.padEnd(43,'A');const uuid='7a5b6f5e-4b7d-4af7-8f30-9de2a744ce87';
const execution='execution';const versions={runner:'0.1.0',playwright:'fixture',chromium:'fixture'};
const png=PNG.sync.write(new PNG({width:1,height:1}));
function error(res,status,code='odvr_api_unavailable',extra={}){res.writeHead(status,{'Content-Type':'application/json'});res.end(JSON.stringify({schema_version:1,code,message:'定型エラー',data:{status,retryable:status===503,request_id:'fixture',...extra}}));}
function json(res,data){res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify(data));}
async function setup(t,handler,expires=60000){const sockets=new Set();const seen=[];const server=https.createServer({key,cert},async(req,res)=>{const chunks=[];for await(const data of req)chunks.push(data);const body=Buffer.concat(chunks);seen.push({path:req.url,headers:req.headers,body});try{await handler(req,res,body);}catch{res.destroy();}});server.on('connection',socket=>{sockets.add(socket);socket.on('close',()=>sockets.delete(socket));});await new Promise(resolve=>server.listen(0,address,resolve));const port=server.address().port;const origin=`https://fixture.test:${port}`;const base=origin+'/wp-json/odvr/v1/runner';const localDestination={origin,address,port};const transport=new PinnedHttpClient({policy:new DestinationPolicy({controlBase:base,captureOrigins:[origin],profile:'local',localDestination}),ca:cert});const client=new WordPressClient({callbackBase:base,runUuid:uuid,executionId:execution,token,tokenExpiresAt:Date.now()+expires,profile:'local',localDestination,transport});t.after(async()=>{client.close();for(const socket of sockets)socket.destroy();await new Promise(resolve=>server.close(resolve));});return {client,seen,origin};}
function manifest(){const data=value('run-manifest');data.run.runner_execution_id=execution;data.run.created_at=new Date().toISOString().slice(0,19)+'Z';data.run.deadline_at=new Date(Date.now()+60000).toISOString().slice(0,19)+'Z';data.reference={mode:'previous',run_id:9,snapshots:[{target_id:1,device_id:1,baseline_snapshot_id:7,reason:null}],versions};return data;}
const result=()=>({...value('snapshot-result'),status:'ERROR',width:null,height:null,error_code:'CAPTURE_FAILED',error_message:'撮影に失敗しました。'});
test('固定HTTPS API・Execution・Bearerだけを使いUpload/Completeの応答喪失を同一バッファで再送する',async t=>{
 const uploads=[];const completes=[];const bodies=[];const {client,seen}=await setup(t,(req,res,body)=>{
   if(req.url.endsWith('/manifest'))return json(res,manifest());
   if(req.url.endsWith('/snapshots')){uploads.push(body);if(uploads.length===1){req.socket.destroy();return;}return json(res,{schema_version:1,snapshot_id:1,status:'ERROR',replayed:true});}
   if(req.url.endsWith('/complete')){completes.push(body);if(completes.length===1){req.socket.destroy();return;}return json(res,{...value('run-state'),status:'failed',error_snapshots:1,pending_snapshots:0,completed_at:new Date().toISOString().slice(0,19)+'Z',error_code:'SNAPSHOT_FAILED'});}
   bodies.push(body);error(res,404);
 });
 await client.manifest();assert.equal((await client.upload(result(),{})).replayed,true);
 await client.complete({schema_version:1,runner_execution_id:execution,versions,outcome:'finished',error_code:null,error_message:null});
 assert.equal(uploads.length,2);assert.ok(uploads[0].equals(uploads[1]));assert.equal(completes.length,2);assert.ok(completes[0].equals(completes[1]));assert.equal(bodies.length,0);
 for(const request of seen){assert.equal(request.headers.authorization,`Bearer ${token}`);assert.equal(request.headers['x-odvr-execution-id'],execution);assert.equal(request.headers.cookie,undefined);assert.ok(!request.path.includes(token));}
 assert.ok(!JSON.stringify(client).includes(token));
});
test('401/403/404では限定retryも別UUID探索も行わない',async t=>{for(const status of [401,403,404]){const {client,seen}=await setup(t,(_req,res)=>error(res,status,'odvr_runner_unauthorized'));await assert.rejects(client.manifest(),error=>error.status===status && !error.retryable);assert.equal(seen.length,1);}});
test('503は初回+2回で打ち切り、原文の秘密を例外へ含めない',async t=>{const {client,seen}=await setup(t,(_req,res)=>error(res,503));await assert.rejects(client.manifest(),error=>error.retryable && !error.message.includes(token));assert.equal(seen.length,3);});
test('固定参照PNGのSHA・長さを検査し欠損理由を限定する',async t=>{let mode='healthy';const {client,seen}=await setup(t,(req,res)=>{if(req.url.endsWith('/manifest'))return json(res,manifest());if(mode==='missing')return error(res,404,'odvr_baseline_unavailable',{reason:'missing'});if(mode==='outside')return error(res,404,'odvr_not_found');res.writeHead(200,{'Content-Type':'image/png','Content-Length':png.length,'X-ODVR-Image-SHA256':mode==='bad' ? '0'.repeat(64):createHash('sha256').update(png).digest('hex')});res.end(png);});await client.manifest();assert.ok((await client.baseline(7)).image.equals(png));mode='missing';assert.deepEqual(await client.baseline(7),{reason:'missing'});mode='bad';await assert.rejects(client.baseline(7),{code:'odvr_invalid_baseline'});mode='outside';await assert.rejects(client.baseline(7),{status:404});const count=seen.length;await assert.rejects(client.baseline(8),{status:404});assert.equal(seen.length,count);});
test('CredentialsのBasic OriginがManifest外なら拒否しHTTPへ送らない',async t=>{const {client}=await setup(t,(req,res)=>req.url.endsWith('/manifest') ? json(res,manifest()):json(res,{schema_version:1,http_auth:{origin:'https://other.test',username:'fixture',password:'confidential'}}));await client.manifest();await assert.rejects(client.credentials(),{code:'odvr_invalid_credentials'});});
test('固定Executionと期限を検査し期限後のAPIを停止する',async t=>{const {client,seen}=await setup(t,(_req,res)=>json(res,manifest()),150);await assert.rejects(client.progress({schema_version:1,runner_execution_id:'different',versions}),{code:'odvr_run_conflict'});assert.equal(seen.length,0);await new Promise(resolve=>setTimeout(resolve,180));await assert.rejects(client.manifest(),{code:'odvr_run_deadline'});assert.equal(seen.length,0);});
test('multipartは微小ratioを指数表記にせずPNGをコピーして固定する',()=>{const captured={...value('snapshot-result'),width:10000,height:10000,status:'REVIEW',baseline_width:10000,baseline_height:10000,dimension_changed:false,diff_pixels:1,total_pixels:100000000,diff_ratio:0.00000001};captured.total_pixels=40000000;captured.width=10000;captured.height=4000;captured.baseline_width=10000;captured.baseline_height=4000;captured.diff_ratio=1/40000000;const bytes=Buffer.from(png);const {body,contentType}=snapshotMultipart(captured,{image:bytes,diff_image:png});bytes.fill(0);assert.ok(body.includes(png));assert.ok(body.toString('latin1').includes('\r\n0.000000025\r\n'));assert.match(contentType,/boundary=odvr-/);assert.throws(()=>snapshotMultipart(result(),{image:png}));});
test('製品Executorを実Chromium・TLS・Basic・Cookieと固定APIへ接続する',async t=>{
 const {executeProductRun,installedVersions}=await import('../dist/jobs/product-run.js');
 const actual=await installedVersions();let origin;let uploads=0;let pageRequests=0;let cookieRequests=0;let controlRequests=0;const basic=`Basic ${Buffer.from('fixture-user:fixture-password').toString('base64')}`;
 const {client,origin:registered}=await setup(t,(req,res,body)=>{
  const api=req.url.startsWith('/wp-json/');
  if(api){controlRequests++;assert.equal(req.headers.authorization,`Bearer ${token}`);assert.equal(req.headers.cookie,undefined);
    if(req.url.endsWith('/manifest')){const data=manifest();data.reference={mode:'previous',run_id:null,snapshots:[{target_id:1,device_id:1,baseline_snapshot_id:null,reason:'no_reference'}],versions:null};data.targets[0].url=origin+'/page';data.devices[0]={...data.devices[0],viewport_width:200,viewport_height:200,device_scale_factor:1};data.allowed_origins=[origin];data.settings.lazy_load=false;return json(res,data);}
    if(req.url.endsWith('/credentials'))return json(res,{schema_version:1,http_auth:{origin,username:'fixture-user',password:'fixture-password'}});
    if(req.url.endsWith('/progress'))return json(res,{...value('run-state'),status:'running',completed_snapshots:uploads,pending_snapshots:1-uploads});
    if(req.url.endsWith('/snapshots')){assert.ok(body.includes(Buffer.from('89504e470d0a1a0a','hex')));assert.ok(body.toString('latin1').includes('CAPTURED'));assert.ok(!body.includes(Buffer.from(token)));assert.ok(!body.includes(Buffer.from('fixture-password')));uploads++;return json(res,{schema_version:1,snapshot_id:1,status:'CAPTURED',replayed:false});}
    if(req.url.endsWith('/complete')){if(!uploads)return error(res,409,'odvr_run_closed');return json(res,{...value('run-state'),status:'complete',completed_snapshots:1,pending_snapshots:0,completed_at:new Date().toISOString().slice(0,19)+'Z'});}
    return error(res,404);
  }
  pageRequests++;assert.equal(req.headers.authorization,basic);assert.ok(!body.includes(Buffer.from(token)));
  if(req.url==='/cookie'){assert.match(req.headers.cookie,/fixture=1/);cookieRequests++;res.end('cookie ok');return;}
  res.writeHead(200,{'Content-Type':'text/html','Set-Cookie':'fixture=1; Path=/; Secure'});res.end('<style>body{margin:0;background:white}</style><script>fetch("/cookie").then(()=>document.body.textContent="ready")</script><div>fixture</div>');
 });origin=registered;
 const port=Number(new URL(origin).port);const state=await executeProductRun(client,{executionId:execution,profile:'local',localDestination:{origin,address,port}},{versions:actual,transport:(policy,deadline)=>new PinnedHttpClient({policy,runDeadline:deadline,ca:cert})});assert.equal(state.status,'complete');assert.equal(uploads,1);assert.ok(pageRequests>=2);assert.equal(cookieRequests,1);assert.ok(controlRequests>=6);
});
