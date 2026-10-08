import type { BrowserContext } from 'playwright';
import type { NetworkDiagnostics, SnapshotErrorCode } from '@odvr/shared';
import { SnapshotError, classifyError } from '../errors.js';
import { validateDestination } from './url-validator.js';
export async function installNetworkGuard(context: BrowserContext, allowedOrigins: string[]): Promise<NetworkDiagnostics> {
  const diagnostics: NetworkDiagnostics = { blocked_resource_count: 0, blocked_resource_reasons: {} };
  const record = (code: SnapshotErrorCode, navigation = false) => {
    diagnostics.blocked_resource_count++;
    diagnostics.blocked_resource_reasons[code] = (diagnostics.blocked_resource_reasons[code] ?? 0) + 1;
    if (navigation) diagnostics.navigation_error_code = code;
  };
  await context.route('**/*', async route => {
    let failure: SnapshotErrorCode | undefined;
    try {
      await validateDestination(route.request().url(), allowedOrigins);
      // 自動リダイレクトは検査を迂回するため、プロトタイプでは拒否する。
      const response = await route.fetch({ maxRedirects: 0, timeout: 30000 });
      try {
        const request = route.request();
        if (request.isNavigationRequest() && request.frame() === request.frame().page().mainFrame()) diagnostics.navigation_http_status = response.status();
        if (response.status() >= 300 && response.status() < 400 && response.status() !== 304) throw new SnapshotError('REDIRECT_BLOCKED');
        if (response.status() >= 400 && !route.request().isNavigationRequest()) record('HTTP_ERROR');
        await route.fulfill({ response });
      } finally { await response.dispose(); }
    } catch (error) { failure = classifyError(error, route.request().isNavigationRequest() ? 'NAVIGATION_TIMEOUT' : 'NETWORK_ERROR').code; }
    if (failure) {
      const request = route.request();
      record(failure, request.isNavigationRequest() && request.frame() === request.frame().page().mainFrame());
      // ページ終了との競合でabort自体が失敗しても、診断を保持する。
      try { await route.abort('blockedbyclient'); } catch { /* 診断は記録済み。 */ }
    }
  });
  // WebSocketは撮影プロトタイプでは使用しない。拒否も不完全な画面として記録する。
  await context.routeWebSocket('**/*', socket => { record('RESOURCE_BLOCKED'); socket.close(); });
  return diagnostics;
}
