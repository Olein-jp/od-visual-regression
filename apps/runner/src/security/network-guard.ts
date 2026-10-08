import type { BrowserContext, Request } from 'playwright';
import type { NetworkDiagnostics, SnapshotErrorCode } from '@odvr/shared';
import { classifyError } from '../errors.js';
import { DestinationPolicy } from './destination-policy.js';
import { PinnedHttpClient, type TransportAuth } from './pinned-http-client.js';
export interface GuardOptions { client?: PinnedHttpClient; targetKey?: string; auth?: TransportAuth }
function mainNavigation(request:Request):boolean {
  try { return request.isNavigationRequest() && request.frame() === request.frame().page().mainFrame(); }
  catch { return false; } // worker要求にはFrameがない。
}
export async function installNetworkGuard(context: BrowserContext, allowedOrigins: string[], options:GuardOptions = {}): Promise<NetworkDiagnostics> {
  const diagnostics: NetworkDiagnostics = { blocked_resource_count: 0, blocked_resource_reasons: {} };
  const client = options.client ?? new PinnedHttpClient({policy:new DestinationPolicy({captureOrigins:allowedOrigins})});
  const controller = new AbortController();
  const targetKey = options.targetKey ?? 'capture';
  let auth = options.auth ? {...options.auth} : undefined;
  const record = (code: SnapshotErrorCode, navigation = false) => {
    diagnostics.blocked_resource_count++;
    diagnostics.blocked_resource_reasons[code] = (diagnostics.blocked_resource_reasons[code] ?? 0) + 1;
    if (navigation) diagnostics.navigation_error_code = code;
  };
  context.once('close',() => { auth=undefined; controller.abort(); if (!options.client) client.close(); });
  await context.route('**/*', async route => {
    const request = route.request();
    const navigation = mainNavigation(request);
    try {
      const requestOrigin = new URL(request.url()).origin;
      const response = await client.request({url:request.url(),purpose:'capture',targetKey,
        method:request.method(),headers:(await request.headersArray()).map(({name,value}) => [name,value] as const),
        body:request.postDataBuffer() ?? undefined,signal:controller.signal,
        auth:auth?.kind === 'basic' && auth.origin === requestOrigin ? auth : undefined});
      if (navigation) diagnostics.navigation_http_status = response.status;
      if (response.status >= 400 && !navigation) record('HTTP_ERROR');
      const headers:Record<string,string> = Object.create(null);
      for (const [name,value] of response.headers) {
        // ChromiumのfulfillはSet-Cookieを改行で分割し、多値のまま適用する。
        headers[name] = Object.hasOwn(headers,name) ? `${headers[name]}${name === 'set-cookie' ? '\n' : ', '}${value}` : value;
      }
      // Workerも含めWSを拒否し、blob/data Workerで応答policyを迂回させない。
      const connectionPolicy = `connect-src ${allowedOrigins.length ? allowedOrigins.join(' ') : "'none'"}; worker-src ${allowedOrigins.length ? allowedOrigins.join(' ') : "'none'"}`;
      headers['content-security-policy'] = headers['content-security-policy'] ? `${headers['content-security-policy']}, ${connectionPolicy}` : connectionPolicy;
      // Playwrightが未指定CORSを自動許可するため、欠落は明示的な空値で保つ。
      if (!Object.hasOwn(headers,'access-control-allow-origin')) headers['access-control-allow-origin'] = '';
      await route.fulfill({status:response.status,headers,body:response.body});
    } catch (error) {
      const failure = classifyError(error,'NETWORK_ERROR');
      if (navigation && failure.http_status !== undefined) diagnostics.navigation_http_status = failure.http_status;
      record(failure.code,navigation);
      try { await route.abort('blockedbyclient'); } catch { /* 終了との競合でも診断を保持する。 */ }
    }
  });
  await context.routeWebSocket('**/*', socket => { record('RESOURCE_BLOCKED'); socket.close(); });
  return diagnostics;
}
