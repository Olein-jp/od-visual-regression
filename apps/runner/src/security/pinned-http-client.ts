import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import tls from 'node:tls';
import { createGunzip, createInflate, createBrotliDecompress } from 'node:zlib';
import type { Transform } from 'node:stream';
import { SnapshotError, classifyError } from '../errors.js';
import type { Resolver } from './url-validator.js';
import { DestinationPolicy, normalizeAddress, type Purpose, type PinnedDestination } from './destination-policy.js';

const MiB = 1024 * 1024;
export const NETWORK_CAPS = Object.freeze({ dnsMs:5000, connectMs:5000, requestMs:30000, idleMs:5000, targetMs:120000, runMs:5400000, headers:32768, requestBody:MiB, uploadBody:42*MiB, captureResponse:10*MiB, controlResponse:MiB, baselineResponse:20*MiB, targetBytes:100*MiB, runBytes:1024*MiB, targetRequests:500, targetConnections:8, runConnections:16 });
export type NetworkLimits = { [K in keyof typeof NETWORK_CAPS]: number };
export type Connector = (destination: PinnedDestination, signal: AbortSignal) => Promise<net.Socket>;
export interface ClientOptions {
  policy: DestinationPolicy;
  resolver?: Resolver;
  connector?: Connector;
  ca?: tls.ConnectionOptions['ca'];
  limits?: Partial<NetworkLimits>;
  runDeadline?: number;
}
export type TransportAuth =
  | Readonly<{ kind:'basic'; origin:string; username:string; password:string; developmentOnly?:boolean }>
  | Readonly<{ kind:'bearer'; token:string; developmentOnly?:boolean }>;
export interface PinnedRequest {
  url: string;
  purpose: Purpose;
  targetKey?: string;
  method?: string;
  headers?: readonly (readonly [string,string])[];
  body?: Uint8Array;
  auth?: TransportAuth;
  signal?: AbortSignal;
}
export interface PinnedResponse {
  readonly status: number;
  readonly headers: readonly (readonly [string,string])[];
  readonly body: Buffer;
}
interface TargetBudget { deadline:number; requests:number; active:number; wire:number; decoded:number; controllers:Set<AbortController>; timer:NodeJS.Timeout }
function abortError(signal: AbortSignal): SnapshotError { return signal.reason instanceof SnapshotError ? signal.reason : new SnapshotError('REQUEST_ABORTED'); }
function waitFor<T>(promise:Promise<T>, ms:number, signal:AbortSignal, code:'DNS_ERROR'|'NETWORK_TIMEOUT'):Promise<T> {
  return new Promise((resolve,reject) => {
    let done = false;
    const deadline = Date.now()+ms;
    const finish = (error?:unknown, value?:T) => { if (done) return; done=true; clearTimeout(timer); signal.removeEventListener('abort',abort); if (error) reject(error); else resolve(value!); };
    const abort = () => finish(abortError(signal));
    const timer = setTimeout(() => finish(new SnapshotError(code)),ms);
    signal.addEventListener('abort',abort,{once:true});
    if (signal.aborted) abort();
    promise.then(value => Date.now() >= deadline ? finish(new SnapshotError(code)) : finish(undefined,value),error => finish(error));
  });
}
export function verifyPeer(socket:net.Socket, destination:PinnedDestination):void {
  if (!socket.remoteAddress || normalizeAddress(socket.remoteAddress) !== destination.address || socket.remotePort !== destination.port) throw new SnapshotError('CONNECTION_MISMATCH');
}
export const connectPinned:Connector = (destination,signal) => new Promise((resolve,reject) => {
  // literalへ直接接続するためhostname再解決・環境proxy・Happy Eyeballsを使用しない。
  const socket = net.createConnection({host:destination.address,port:destination.port,family:destination.family,autoSelectFamily:false});
  const abort = () => { socket.destroy(); reject(abortError(signal)); };
  signal.addEventListener('abort',abort,{once:true});
  socket.once('error',error => { signal.removeEventListener('abort',abort); reject(classifyError(error,'NETWORK_ERROR')); });
  socket.once('connect',() => {
    signal.removeEventListener('abort',abort);
    try { if (signal.aborted) throw abortError(signal); verifyPeer(socket,destination); resolve(socket); }
    catch(error) { socket.destroy(); reject(error); }
  });
  if (signal.aborted) abort();
});

/** Run単位で所有し、Context終了・Token失効・Run終了時にcloseする。 */
export class PinnedHttpClient {
  readonly limits: NetworkLimits;
  private readonly options: ClientOptions;
  private readonly controllers = new Set<AbortController>();
  private readonly targets = new Map<string,TargetBudget>();
  private readonly runTimer: NodeJS.Timeout;
  private readonly runDeadline: number;
  private active = 0;
  private wire = 0;
  private decoded = 0;
  private closed?: SnapshotError;
  constructor(options:ClientOptions) {
    const limits = {...NETWORK_CAPS,...options.limits};
    for (const [key,value] of Object.entries(limits)) if (!(key in NETWORK_CAPS) || !Number.isInteger(value) || value < 1 || value > NETWORK_CAPS[key as keyof NetworkLimits]) throw new SnapshotError('NETWORK_LIMIT_EXCEEDED');
    this.limits = Object.freeze(limits);
    this.options = {...options};
    const remaining = Math.min(limits.runMs,(options.runDeadline ?? Date.now()+limits.runMs)-Date.now());
    if (!Number.isFinite(remaining) || remaining <= 0) throw new SnapshotError('NETWORK_TIMEOUT');
    this.runDeadline = Date.now()+remaining;
    this.runTimer = setTimeout(() => this.close(new SnapshotError('NETWORK_TIMEOUT')),remaining);
    this.runTimer.unref();
  }
  get activeConnections():number { return this.active; }
  close(reason = new SnapshotError('REQUEST_ABORTED')):void {
    if (this.closed) return;
    this.closed = reason;
    clearTimeout(this.runTimer);
    for (const target of this.targets.values()) clearTimeout(target.timer);
    for (const controller of this.controllers) controller.abort(reason);
  }
  private target(key:string):TargetBudget {
    let target = this.targets.get(key);
    if (!target) {
      if (this.targets.size >= 1000) throw new SnapshotError('NETWORK_LIMIT_EXCEEDED');
      target = {deadline:Date.now()+this.limits.targetMs,requests:0,active:0,wire:0,decoded:0,controllers:new Set(),timer:setTimeout(() => {
        for (const controller of target!.controllers) controller.abort(new SnapshotError('NETWORK_TIMEOUT'));
        // 期限後の新規要求も拒否する。
        target!.requests = this.limits.targetRequests;
      },this.limits.targetMs)};
      target.timer.unref();
      this.targets.set(key,target);
    }
    return target;
  }
  private consume(kind:'wire'|'decoded', bytes:number, target?:TargetBudget):void {
    this[kind] += bytes;
    if (target) target[kind] += bytes;
    if (this[kind] > this.limits.runBytes) { this.close(new SnapshotError('NETWORK_LIMIT_EXCEEDED')); throw new SnapshotError('NETWORK_LIMIT_EXCEEDED'); }
    if (target && target[kind] > this.limits.targetBytes) {
      for (const controller of target.controllers) controller.abort(new SnapshotError('NETWORK_LIMIT_EXCEEDED'));
      target.requests = this.limits.targetRequests;
      throw new SnapshotError('NETWORK_LIMIT_EXCEEDED');
    }
  }
  async request(requestInput:PinnedRequest):Promise<PinnedResponse> {
    const input = {...requestInput,auth:requestInput.auth ? {...requestInput.auth} : undefined};
    if (Date.now() >= this.runDeadline) this.close(new SnapshotError('NETWORK_TIMEOUT'));
    if (this.closed) throw this.closed;
    const url = this.options.policy.validate(input.url,input.purpose);
    const method = (input.method ?? 'GET').toUpperCase();
    if (!['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS'].includes(method)) throw new SnapshotError('URL_BLOCKED');
    if (input.purpose !== 'capture' && method !== (['manifest','credentials','baseline'].includes(input.purpose) ? 'GET' : 'POST')) throw new SnapshotError('URL_BLOCKED');
    if ((input.body?.byteLength ?? 0) > (input.purpose === 'upload' ? this.limits.uploadBody : this.limits.requestBody)) throw new SnapshotError('NETWORK_LIMIT_EXCEEDED');
    const body = input.body ? Buffer.from(input.body) : undefined;
    if ((body?.length ?? 0) > (input.purpose === 'upload' ? this.limits.uploadBody : this.limits.requestBody) || (['GET','HEAD'].includes(method) && body?.length)) throw new SnapshotError('NETWORK_LIMIT_EXCEEDED');
    const headers = this.headers(input.headers ?? []);
    if (input.auth) this.validateAuth(input.auth,url,input.purpose);
    if (input.purpose === 'capture' && (!input.targetKey || input.targetKey.length > 100)) throw new SnapshotError('URL_BLOCKED');
    const target = input.purpose === 'capture' ? this.target(input.targetKey ?? (() => { throw new SnapshotError('URL_BLOCKED'); })()) : undefined;
    if (target && Date.now() >= target.deadline) throw new SnapshotError('NETWORK_TIMEOUT');
    if (this.active >= this.limits.runConnections || (target && (target.active >= this.limits.targetConnections || target.requests >= this.limits.targetRequests))) throw new SnapshotError('NETWORK_LIMIT_EXCEEDED');
    const controller = new AbortController();
    const externalAbort = () => controller.abort(new SnapshotError('REQUEST_ABORTED'));
    input.signal?.addEventListener('abort',externalAbort,{once:true});
    if (input.signal?.aborted) externalAbort();
    const requestDeadline = Date.now()+this.limits.requestMs;
    const overall = setTimeout(() => controller.abort(new SnapshotError('NETWORK_TIMEOUT')),this.limits.requestMs);
    this.controllers.add(controller); this.active++;
    if (target) { target.requests++; target.active++; target.controllers.add(controller); }
    let socket:net.Socket|undefined;
    let agent:http.Agent|https.Agent|undefined;
    try {
      if (controller.signal.aborted) throw abortError(controller.signal);
      const destination = await waitFor(this.options.policy.resolve(input.url,input.purpose,this.options.resolver),this.limits.dnsMs,controller.signal,'DNS_ERROR');
      const connector = this.options.connector ?? connectPinned;
      const connectDeadline = Date.now() + this.limits.connectMs;
      const connectPromise = connector(destination,controller.signal).then(value => {
        if (controller.signal.aborted) { value.destroy(); throw abortError(controller.signal); }
        return value;
      });
      try { socket = await waitFor(connectPromise,this.limits.connectMs,controller.signal,'NETWORK_TIMEOUT'); }
      catch(error) { controller.abort(error); throw error; }
      verifyPeer(socket,destination);
      const hostname = url.hostname.replace(/^\[|\]$/g,'');
      if (url.protocol === 'https:') {
        const remaining = connectDeadline-Date.now();
        if (remaining <= 0) throw new SnapshotError('NETWORK_TIMEOUT');
        const secure = tls.connect({socket,host:hostname,servername:net.isIP(hostname) ? undefined : hostname,rejectUnauthorized:true,ca:this.options.ca,checkServerIdentity:(_name,certificate) => tls.checkServerIdentity(hostname,certificate)});
        socket = secure;
        await waitFor(new Promise<void>((resolve,reject) => { secure.once('secureConnect',() => secure.authorized ? resolve() : reject(new SnapshotError('TLS_ERROR'))); secure.once('error',() => reject(new SnapshotError('TLS_ERROR'))); }),remaining,controller.signal,'NETWORK_TIMEOUT');
        verifyPeer(secure,destination);
      }
      if (controller.signal.aborted) throw abortError(controller.signal);
      if (Date.now() >= Math.min(requestDeadline,this.runDeadline,target?.deadline ?? Infinity)) throw new SnapshotError('NETWORK_TIMEOUT');
      // Hostと認証は検査済みの元hostnameから生成し、接続先確認後にのみHTTPへ渡す。
      headers.host = url.host;
      headers.connection = 'close';
      if (body) headers['content-length'] = String(body.length);
      if (input.auth?.kind === 'bearer') headers.authorization = `Bearer ${input.auth.token}`;
      if (input.auth?.kind === 'basic') headers.authorization = `Basic ${Buffer.from(`${input.auth.username}:${input.auth.password}`).toString('base64')}`;
      if (headerSize(Object.entries(headers)) > this.limits.headers) throw new SnapshotError('NETWORK_LIMIT_EXCEEDED');
      const connected = socket;
      agent = url.protocol === 'https:' ? new https.Agent({keepAlive:false,maxSockets:1}) : new http.Agent({keepAlive:false,maxSockets:1});
      agent.createConnection = () => connected;
      const responseLimit = input.purpose === 'capture' ? this.limits.captureResponse : input.purpose === 'baseline' ? this.limits.baselineResponse : this.limits.controlResponse;
      return await this.exchange(url,method,headers,body,agent,connected,controller,target,responseLimit,Math.min(requestDeadline,this.runDeadline,target?.deadline ?? Infinity));
    } catch(error) {
      throw controller.signal.aborted ? abortError(controller.signal) : classifyError(error,'NETWORK_ERROR');
    } finally {
      clearTimeout(overall);
      input.signal?.removeEventListener('abort',externalAbort);
      controller.abort(new SnapshotError('REQUEST_ABORTED'));
      socket?.destroy(); agent?.destroy();
      this.controllers.delete(controller); this.active--;
      if (target) { target.controllers.delete(controller); target.active--; }
    }
  }
  private headers(values:readonly (readonly [string,string])[]):Record<string,string> {
    if (headerSize(values) > this.limits.headers) throw new SnapshotError('NETWORK_LIMIT_EXCEEDED');
    const connectionNames = values.filter(([name]) => name.toLowerCase() === 'connection').flatMap(([,value]) => value.toLowerCase().split(',').map(x => x.trim()));
    const forbidden = new Set([...connectionNames,'authorization','proxy-authorization','host','connection','proxy-connection','keep-alive','transfer-encoding','te','trailer','upgrade','metadata-flavor','content-length','if-none-match','if-modified-since']);
    const result:Record<string,string> = Object.create(null);
    for (const [name,value] of values) {
      try { http.validateHeaderName(name); http.validateHeaderValue(name,value); } catch { throw new SnapshotError('URL_BLOCKED'); }
      const key = name.toLowerCase();
      if (!forbidden.has(key)) result[key] = Object.hasOwn(result,key) ? `${result[key]}${key === 'cookie' ? '; ' : ', '}${value}` : value;
    }
    return result;
  }
  private validateAuth(auth:TransportAuth,url:URL,purpose:Purpose):void {
    if (!['basic','bearer'].includes(auth.kind)) throw new SnapshotError('URL_BLOCKED');
    const localHttp = this.options.policy.localDestination?.origin === url.origin && url.protocol === 'http:' && auth.developmentOnly === true;
    if (url.protocol !== 'https:' && !localHttp) throw new SnapshotError('URL_BLOCKED');
    if (auth.kind === 'basic') {
      if (purpose !== 'capture' || auth.origin !== url.origin || !auth.username || !auth.password || auth.username.includes(':') || /[\x00-\x1f\x7f]/.test(auth.username+auth.password)) throw new SnapshotError('ORIGIN_BLOCKED');
    } else if (purpose === 'capture' || auth.token.length !== 43 || !/^[A-Za-z0-9_-]+$/.test(auth.token)) throw new SnapshotError('ORIGIN_BLOCKED');
  }
  private exchange(url:URL,method:string,headers:Record<string,string>,body:Buffer|undefined,agent:http.Agent,socket:net.Socket,controller:AbortController,target:TargetBudget|undefined,limit:number,deadline:number):Promise<PinnedResponse> {
    return new Promise((resolve,reject) => {
      let finished = false;
      let raw:http.IncomingMessage|undefined;
      let decoder:Transform|undefined;
      let wire = 0;
      let decoded = 0;
      let idle:NodeJS.Timeout;
      const chunks:Buffer[] = [];
      const finish = (error?:unknown,response?:PinnedResponse) => {
        if (finished) return; finished = true;
        clearTimeout(idle); socket.removeListener('data',wireData); controller.signal.removeEventListener('abort',abort);
        if (error) { chunks.length = 0; raw?.destroy(); decoder?.destroy(); request.destroy(); socket.destroy(); reject(error); }
        else resolve(response!);
      };
      const abort = () => finish(abortError(controller.signal));
      const resetIdle = () => { clearTimeout(idle); idle = setTimeout(() => controller.abort(new SnapshotError('NETWORK_TIMEOUT')),this.limits.idleMs); };
      const wireData = (chunk:Buffer) => {
        try { if (Date.now() >= deadline) throw new SnapshotError('NETWORK_TIMEOUT'); wire += chunk.length; this.consume('wire',chunk.length,target); if (wire > limit) throw new SnapshotError('NETWORK_LIMIT_EXCEEDED'); resetIdle(); }
        catch(error) { finish(error); }
      };
      socket.on('data',wireData);
      const request = (url.protocol === 'https:' ? https : http).request(url,{method,headers,agent,maxHeaderSize:this.limits.headers},response => {
        raw = response;
        const pairs: [string,string][] = [];
        for (let index=0;index<response.rawHeaders.length;index+=2) pairs.push([response.rawHeaders[index].toLowerCase(),response.rawHeaders[index+1]]);
        const status = response.statusCode ?? 0;
        if (status < 100 || status > 599) { finish(new SnapshotError('HTTP_ERROR')); return; }
        if (status >= 300 && status < 400) { finish(new SnapshotError(status === 304 ? 'HTTP_ERROR' : 'REDIRECT_BLOCKED')); return; }
        if (headerSize(pairs) > this.limits.headers || (response.headers['content-length'] && Number(response.headers['content-length']) > limit)) { finish(new SnapshotError('NETWORK_LIMIT_EXCEEDED')); return; }
        const encoding = method === 'HEAD' || status === 204 ? 'identity' : (response.headers['content-encoding'] ?? 'identity').toLowerCase();
        if (!['identity','gzip','deflate','br'].includes(encoding)) { finish(new SnapshotError('NETWORK_ERROR')); return; }
        decoder = encoding === 'gzip' ? createGunzip() : encoding === 'deflate' ? createInflate() : encoding === 'br' ? createBrotliDecompress() : undefined;
        const stream = decoder ?? response;
        stream.on('data',(chunk:Buffer) => {
          try { if (Date.now() >= deadline) throw new SnapshotError('NETWORK_TIMEOUT'); decoded += chunk.length; this.consume('decoded',chunk.length,target); if (decoded > limit) throw new SnapshotError('NETWORK_LIMIT_EXCEEDED'); chunks.push(chunk); }
          catch(error) { finish(error); }
        });
        stream.once('error',() => finish(new SnapshotError('NETWORK_ERROR')));
        response.once('aborted',() => finish(new SnapshotError('NETWORK_ERROR')));
        response.once('error',() => finish(new SnapshotError('NETWORK_ERROR')));
        stream.once('end',() => {
          if (Date.now() >= deadline) { finish(new SnapshotError('NETWORK_TIMEOUT')); return; }
          const connectionNames = (response.headers.connection ?? '').toLowerCase().split(',').map(x => x.trim());
          const excluded = new Set([...connectionNames,'connection','keep-alive','proxy-authenticate','proxy-authorization','te','trailer','transfer-encoding','upgrade','content-encoding','content-length']);
          const finalHeaders = pairs.filter(([key]) => !excluded.has(key));
          finalHeaders.push(['content-length',String(decoded)]);
          finish(undefined,Object.freeze({status,headers:Object.freeze(finalHeaders.map(x => Object.freeze(x))),body:Buffer.concat(chunks,decoded)}));
        });
        if (decoder) response.pipe(decoder);
      });
      request.once('error',error => finish(error instanceof Error && 'code' in error && error.code === 'HPE_HEADER_OVERFLOW' ? new SnapshotError('NETWORK_LIMIT_EXCEEDED') : classifyError(error,'NETWORK_ERROR')));
      request.once('upgrade',() => finish(new SnapshotError('NETWORK_ERROR')));
      controller.signal.addEventListener('abort',abort,{once:true});
      resetIdle();
      if (controller.signal.aborted) { abort(); return; }
      request.end(body);
    });
  }
}
function headerSize(headers:readonly (readonly [string,string])[]):number { return headers.reduce((sum,[name,value]) => sum+Buffer.byteLength(name)+Buffer.byteLength(value)+4,2); }
