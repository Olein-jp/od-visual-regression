import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import { networkInterfaces } from 'node:os';
import { readFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { gzipSync } from 'node:zlib';
import { DestinationPolicy } from '../dist/security/destination-policy.js';
import { PinnedHttpClient, verifyPeer, NETWORK_CAPS } from '../dist/security/pinned-http-client.js';
import { isPublicAddress } from '../dist/security/url-validator.js';
import { SnapshotError } from '../dist/errors.js';

const address = Object.values(networkInterfaces()).flat().find(x => x.family === 'IPv4' && !x.internal && /^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/.test(x.address))?.address;
assert.ok(address,'単一RFC1918先を持つ実ソケットfixtureが必要です。検証をskipしません。');
const key = readFileSync(new URL('./fixtures/tls/fixture-key.pem',import.meta.url));
const cert = readFileSync(new URL('./fixtures/tls/fixture-cert.pem',import.meta.url));
async function fixture(t,handler,secure=false,hostname='fixture.test',tlsOptions={}) {
  const sockets = new Set();
  const server = secure ? https.createServer({key,cert,...tlsOptions},handler) : http.createServer(handler);
  server.on('connection',socket => { sockets.add(socket); socket.on('close',() => sockets.delete(socket)); });
  await new Promise(resolve => server.listen(0,address,resolve));
  const port = server.address().port;
  const origin = `${secure?'https':'http'}://${hostname}:${port}`;
  const policy = new DestinationPolicy({profile:'local',captureOrigins:[origin],localDestination:{origin,address,port},controlBase:`${origin}/wp-json/odvr/v1/runner`});
  const clients = [];
  const client = (options={}) => { const value = new PinnedHttpClient({policy,ca:cert,...options}); clients.push(value); return value; };
  t.after(async () => { for (const value of clients) value.close(); for (const socket of sockets) socket.destroy(); await new Promise(resolve => server.close(resolve)); });
  return {origin,port,policy,client,server};
}
const capture = (origin,extra={}) => ({url:`${origin}/`,purpose:'capture',targetKey:'1:1',...extra});
const code = expected => error => { assert.ok(error instanceof SnapshotError); assert.equal(error.code,expected); return true; };

test('全DNS回答・IP変換・IPv6特殊範囲を検査し不変のliteralへ固定する',async () => {
  const policy = new DestinationPolicy({captureOrigins:['https://fixture.test']});
  for (const answers of [[],Array.from({length:33},() => ({address:'8.8.8.8'})),[{address:'8.8.8.8'},{address:'127.0.0.1'}],[{address:'8.8.8.8'},{address:'fd20:ce::254'}],[{address:'not-an-ip'}]]) await assert.rejects(policy.resolve('https://fixture.test/','capture',async () => answers));
  for (const ip of ['64:ff9b::808:808','64:ff9b:1::808:808','2002:0808:0808::1','2001::1','2001:2::1','2001:db8::1','3fff::1','fe80::1%eth0']) assert.equal(isPublicAddress(ip),false,ip);
  const mapped = await policy.resolve('https://fixture.test/','capture',async () => [{address:'::ffff:8.8.8.8'}]);
  assert.equal(mapped.address,'8.8.8.8'); assert.equal(mapped.family,4); assert.ok(Object.isFrozen(mapped));
  const v6 = await policy.resolve('https://fixture.test/','capture',async () => [{address:'2001:4860:4860::8888'}]);
  assert.equal(v6.family,6);
  assert.doesNotThrow(() => verifyPeer({remoteAddress:v6.address,remotePort:443},v6));
  assert.throws(() => verifyPeer({remoteAddress:'::ffff:127.0.0.1',remotePort:443},v6),code('CONNECTION_MISMATCH'));
});
test('用途を分離し、API経路・認証HTTP・開発例外の範囲を固定する',() => {
  const policy = new DestinationPolicy({captureOrigins:['https://fixture.test'],controlBase:'https://control.test/wp-json/odvr/v1/runner'});
  const uuid='7a5b6f5e-4b7d-4af7-8f30-9de2a744ce87';
  assert.equal(policy.validate(`https://control.test/wp-json/odvr/v1/runner/runs/${uuid}/manifest`,'manifest').hostname,'control.test');
  for (const [url,purpose] of [['https://control.test/wp-json/odvr/v1/suites','manifest'],[`https://control.test/wp-json/odvr/v1/runner/runs/${uuid}/credentials`,'manifest'],['https://control.test/wp-json/odvr/v1/runner/snapshots/1/baseline?token=secret','baseline'],['https://control.test/','capture'],['https://fixture.test/','progress']]) assert.throws(() => policy.validate(url,purpose));
  for (const ip of ['127.0.0.1','169.254.169.254','fd00::1','8.8.8.8']) assert.throws(() => new DestinationPolicy({profile:'local',captureOrigins:['http://fixture.test'],localDestination:{origin:'http://fixture.test',address:ip,port:80}}));
  assert.throws(() => new DestinationPolicy({captureOrigins:['http://fixture.test'],localDestination:{origin:'http://fixture.test',address:'192.168.1.1',port:80}}));
  assert.throws(() => new PinnedHttpClient({policy,limits:{requestMs:NETWORK_CAPS.requestMs+1}}));
});
test('検査直後にDNS回答を切り替えても接続時に再解決せず、次の非公開回答を拒否する',async t => {
  const {origin,port} = await fixture(t,(_req,res) => res.end('unused'));
  const publicOrigin=`https://public.test:${port}`;
  const policy=new DestinationPolicy({captureOrigins:[publicOrigin]});
  let answers=[{address:'8.8.8.8'}], resolutions=0, connections=0;
  const client=new PinnedHttpClient({policy,resolver:async () => { resolutions++; return answers; },connector:async destination => {
    connections++; assert.ok(Object.isFrozen(destination)); assert.equal(destination.address,'8.8.8.8'); assert.equal(destination.port,port);
    answers=[{address:'169.254.169.254'}]; throw new SnapshotError('NETWORK_ERROR');
  }});
  t.after(() => client.close());
  await assert.rejects(client.request(capture(publicOrigin)),code('NETWORK_ERROR'));
  assert.equal(resolutions,1); assert.equal(connections,1);
  await assert.rejects(client.request(capture(publicOrigin)),code('IP_BLOCKED'));
  assert.equal(resolutions,2); assert.equal(connections,1);
  assert.ok(origin); assert.equal(client.activeConnections,0);
});
test('実socketのremoteAddress/port不一致ではHTTP本文・資格情報を送らない',async t => {
  let received=0;
  const {origin,port,client} = await fixture(t,req => { received++; req.resume(); });
  const value=client({connector:async destination => {
    const socket=net.connect({host:address,port:destination.port});
    await new Promise((resolve,reject) => { socket.once('connect',resolve); socket.once('error',reject); });
    Object.defineProperty(socket,'remotePort',{value:port+1});
    return socket;
  }});
  await assert.rejects(value.request(capture(origin,{method:'POST',body:Buffer.from('fixture-only-body'),auth:{kind:'basic',origin,username:'test',password:'fixture-only',developmentOnly:true}})),code('CONNECTION_MISMATCH'));
  assert.equal(received,0); assert.equal(value.activeConnections,0);
});
test('元Host・SNI・証明書名・Basic/Cookieを保ち、多値Set-Cookieと圧縮/CSPを返す',async t => {
  let seen;
  const {origin,client} = await fixture(t,(req,res) => {
    seen={headers:req.headers,servername:req.socket.servername};
    res.writeHead(200,{'Set-Cookie':['one=1; Path=/','two=2; Path=/'],'Content-Encoding':'gzip','Content-Security-Policy':"default-src 'self'"}); res.end(gzipSync('fixture-body'));
  },true);
  const response=await client().request(capture(origin,{headers:[['Host','evil.test'],['Authorization','untrusted'],['Proxy-Authorization','untrusted'],['Metadata-Flavor','Google'],['Cookie','one=1'],['Cookie','two=2'],['Connection','x-hop'],['x-hop','remove']],auth:{kind:'basic',origin,username:'test',password:'fixture-only'}}));
  assert.equal(seen.headers.host,new URL(origin).host); assert.equal(seen.servername,'fixture.test');
  assert.equal(seen.headers.authorization,`Basic ${Buffer.from('test:fixture-only').toString('base64')}`);
  assert.equal(seen.headers.cookie,'one=1; two=2');
  for (const name of ['proxy-authorization','metadata-flavor','x-hop']) assert.equal(seen.headers[name],undefined);
  assert.equal(response.body.toString(),'fixture-body'); assert.equal(response.headers.filter(([name]) => name==='set-cookie').length,2);
  assert.ok(response.headers.some(([name]) => name==='content-security-policy')); assert.ok(!response.headers.some(([name]) => name==='content-encoding'));
});
test('自己署名未信頼・証明書名不一致は認証情報を送信する前に拒否する',async t => {
  let accepted=0;
  const first=await fixture(t,(_req,res) => { accepted++; res.end(); },true);
  await assert.rejects(first.client({ca:undefined}).request(capture(first.origin,{auth:{kind:'basic',origin:first.origin,username:'test',password:'fixture-only'}})),code('TLS_ERROR'));
  const wrong=await fixture(t,(_req,res) => { accepted++; res.end(); },true,'wrong.test');
  await assert.rejects(wrong.client().request(capture(wrong.origin,{auth:{kind:'basic',origin:wrong.origin,username:'test',password:'fixture-only'}})),code('TLS_ERROR'));
  assert.equal(accepted,0);
});
test('IP SANで検証しIP literalにSNIを付けない',async t => {
  const directory=mkdtempSync(join(tmpdir(),'odvr-tls-'));
  t.after(() => rmSync(directory,{recursive:true,force:true}));
  execFileSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',join(directory,'key.pem'),'-out',join(directory,'cert.pem'),'-days','1','-subj','/CN=fixture.test','-addext',`subjectAltName=IP:${address}`],{stdio:'ignore'});
  const certificate=readFileSync(join(directory,'cert.pem'));
  let servername;
  const {origin,client} = await fixture(t,(req,res) => { servername=req.socket.servername; res.end('ip-san'); },true,address,{key:readFileSync(join(directory,'key.pem')),cert:certificate});
  assert.equal((await client({ca:certificate}).request(capture(origin))).body.toString(),'ip-san'); assert.equal(servername,false);
});
test('全redirectと想定外304を拒否し、Location先のaccept数を0に保つ',async t => {
  let forbidden=0;
  const target=await fixture(t,(_req,res) => { forbidden++; res.end(); });
  const {origin,client} = await fixture(t,(req,res) => { res.writeHead(Number(req.url.slice(1)),{Location:target.origin}); res.end(); });
  const value=client();
  for (const status of [300,301,302,303,304,305,307,308,399]) await assert.rejects(value.request(capture(origin,{url:`${origin}/${status}`})),code(status===304?'HTTP_ERROR':'REDIRECT_BLOCKED'));
  assert.equal(forbidden,0);
});
test('wire上限・展開bomb・header上限・送信body/header上限で中断する',async t => {
  const {origin,client} = await fixture(t,(req,res) => {
    if (req.url==='/gzip') { res.writeHead(200,{'Content-Encoding':'gzip'}); res.end(gzipSync(Buffer.alloc(1024*1024))); }
    else if (req.url==='/header') { res.setHeader('x-large','x'.repeat(2000)); res.end(); }
    else { res.writeHead(200); res.end(Buffer.alloc(2000)); }
  });
  const value=client({limits:{captureResponse:512,headers:512,requestBody:64}});
  for (const path of ['/gzip','/wire']) await assert.rejects(value.request(capture(origin,{url:origin+path})),code('NETWORK_LIMIT_EXCEEDED'));
  await assert.rejects(client({limits:{captureResponse:4096,headers:512}}).request(capture(origin,{url:origin+'/header'})),code('NETWORK_LIMIT_EXCEEDED'));
  await assert.rejects(value.request(capture(origin,{method:'POST',body:Buffer.alloc(65)})),code('NETWORK_LIMIT_EXCEEDED'));
  await assert.rejects(value.request(capture(origin,{headers:[['x-large','x'.repeat(513)]]})),code('NETWORK_LIMIT_EXCEEDED'));
  assert.equal(value.activeConnections,0);
});
test('slow drip・idle・絶対要求期限・Target期限・Run期限でsocketを閉じる',async t => {
  const {origin,client} = await fixture(t,(req,res) => {
    if (req.url==='/idle') return;
    res.writeHead(200); res.write('.'); const interval=setInterval(() => res.write('.'),20); req.on('close',() => clearInterval(interval));
  });
  for (const [path,limits] of [['/drip',{requestMs:120,idleMs:60}],['/idle',{requestMs:500,idleMs:60}],['/target',{requestMs:500,idleMs:60,targetMs:100}],['/run',{requestMs:500,idleMs:60,runMs:100}]]) {
    const value=client({limits}); await assert.rejects(value.request(capture(origin,{url:origin+path})),code('NETWORK_TIMEOUT')); assert.equal(value.activeConnections,0);
  }
});
test('DNS・TLSの独立期限とabort/closeにより待機やsocketが残らない',async t => {
  const policy=new DestinationPolicy({captureOrigins:['https://fixture.test']});
  const dns=new PinnedHttpClient({policy,resolver:async () => new Promise(() => {}),limits:{dnsMs:30}});
  t.after(() => dns.close()); await assert.rejects(dns.request(capture('https://fixture.test')),code('DNS_ERROR')); assert.equal(dns.activeConnections,0);
  const first=await fixture(t,() => {});
  const value=first.client(); const controller=new AbortController(); const pending=value.request(capture(first.origin,{signal:controller.signal})); controller.abort(); await assert.rejects(pending,code('REQUEST_ABORTED')); assert.equal(value.activeConnections,0);
  const closing=first.client(); const next=closing.request(capture(first.origin)); closing.close(); await assert.rejects(next,code('REQUEST_ABORTED')); assert.equal(closing.activeConnections,0);
  const sockets=new Set();
  const slow=net.createServer(socket => { sockets.add(socket); socket.on('close',() => sockets.delete(socket)); });
  await new Promise(resolve => slow.listen(0,address,resolve));
  t.after(async () => { for (const socket of sockets) socket.destroy(); await new Promise(resolve => slow.close(resolve)); });
  const origin=`https://fixture.test:${slow.address().port}`;
  const tlsClient=new PinnedHttpClient({policy:new DestinationPolicy({profile:'local',captureOrigins:[origin],localDestination:{origin,address,port:slow.address().port}}),limits:{connectMs:60,requestMs:500}});
  t.after(() => tlsClient.close()); await assert.rejects(tlsClient.request(capture(origin)),code('NETWORK_TIMEOUT')); assert.equal(tlsClient.activeConnections,0);
});
test('要求数・同時接続・Target/Run byte予算を共有し、無限queueを作らない',async t => {
  const first=await fixture(t,(_req,res) => res.end(Buffer.alloc(200)));
  const count=first.client({limits:{targetRequests:1}}); await count.request(capture(first.origin)); await assert.rejects(count.request(capture(first.origin)),code('NETWORK_LIMIT_EXCEEDED'));
  for (const limits of [{targetBytes:500},{runBytes:500}]) {
    const value=first.client({limits}); await value.request(capture(first.origin)); await assert.rejects(value.request(capture(first.origin)),code('NETWORK_LIMIT_EXCEEDED')); assert.equal(value.activeConnections,0);
  }
  const hanging=await fixture(t,() => {});
  const limited=hanging.client({limits:{runConnections:1,targetConnections:1}});
  const pending=limited.request(capture(hanging.origin)); await assert.rejects(limited.request(capture(hanging.origin,{targetKey:'2:1'})),code('NETWORK_LIMIT_EXCEEDED')); limited.close(); await assert.rejects(pending,code('REQUEST_ABORTED')); assert.equal(limited.activeConnections,0);
});
test('Basic/Bearerを別用途・Originへ転用せず、制御HTTPは開発用だけ許可する',async t => {
  let authorization;
  const {origin,client} = await fixture(t,(req,res) => { authorization=req.headers.authorization; res.end('ok'); });
  const value=client(); const uuid='7a5b6f5e-4b7d-4af7-8f30-9de2a744ce87';
  await assert.rejects(value.request(capture(origin,{auth:{kind:'bearer',token:'A'.repeat(43),developmentOnly:true}})),code('ORIGIN_BLOCKED'));
  await assert.rejects(value.request(capture(origin,{auth:{kind:'basic',origin,username:'test',password:'fixture-only'}})),code('URL_BLOCKED'));
  const request={url:`${origin}/wp-json/odvr/v1/runner/runs/${uuid}/manifest`,purpose:'manifest'};
  await assert.rejects(value.request({...request,auth:{kind:'basic',origin,username:'test',password:'fixture-only',developmentOnly:true}}),code('ORIGIN_BLOCKED'));
  await value.request({...request,auth:{kind:'bearer',token:'A'.repeat(43),developmentOnly:true}}); assert.equal(authorization,`Bearer ${'A'.repeat(43)}`);
});

test('IPv6の実socket不一致でもHTTP・資格情報を送らない',async t => {
  let bytes=0;
  const sockets=new Set();
  const server=net.createServer(socket => { sockets.add(socket); socket.on('data',chunk => { bytes+=chunk.length; }); socket.on('close',() => sockets.delete(socket)); });
  await new Promise((resolve,reject) => { server.once('error',reject); server.listen(0,'::1',resolve); });
  const port=server.address().port;
  const origin=`https://fixture.test:${port}`;
  const client=new PinnedHttpClient({policy:new DestinationPolicy({captureOrigins:[origin]}),resolver:async () => [{address:'2001:4860:4860::8888'}],connector:async destination => {
    assert.equal(destination.family,6);
    const socket=net.connect({host:'::1',port,family:6,autoSelectFamily:false});
    await new Promise((resolve,reject) => { socket.once('connect',resolve); socket.once('error',reject); });
    return socket;
  }});
  t.after(async () => { client.close(); for (const socket of sockets) socket.destroy(); await new Promise(resolve => server.close(resolve)); });
  await assert.rejects(client.request(capture(origin,{method:'POST',body:Buffer.from('fixture-only'),auth:{kind:'basic',origin,username:'test',password:'fixture-only'}})),code('CONNECTION_MISMATCH'));
  assert.equal(bytes,0); assert.equal(client.activeConnections,0);
});
test('環境proxyを使わず、指定済みのIP/portへ実接続する',async t => {
  let proxyRequests=0;
  const proxy=await fixture(t,(_req,res) => { proxyRequests++; res.end(); });
  const destination=await fixture(t,(_req,res) => res.end('pinned'));
  const saved=Object.fromEntries(['HTTP_PROXY','HTTPS_PROXY','ALL_PROXY','http_proxy','https_proxy','all_proxy'].map(key => [key,process.env[key]]));
  for (const key of Object.keys(saved)) process.env[key]=proxy.origin;
  try { assert.equal((await destination.client().request(capture(destination.origin))).body.toString(),'pinned'); }
  finally { for (const [key,value] of Object.entries(saved)) if (value === undefined) delete process.env[key]; else process.env[key]=value; }
  assert.equal(proxyRequests,0);
});
test('cloudでlocal設定・Cloud Run実行環境のlocal profileを拒否する',() => {
  const previous=process.env.CLOUD_RUN_JOB;
  process.env.CLOUD_RUN_JOB='fixture-only';
  try { assert.throws(() => new DestinationPolicy({profile:'local',captureOrigins:['http://fixture.test'],localDestination:{origin:'http://fixture.test',address:'192.168.1.1',port:80}}),code('IP_BLOCKED')); }
  finally { if (previous === undefined) delete process.env.CLOUD_RUN_JOB; else process.env.CLOUD_RUN_JOB=previous; }
});
