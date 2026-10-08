import type { BrowserContext } from 'playwright';
import { validateDestination } from './url-validator.js';
export async function installNetworkGuard(context: BrowserContext, allowedOrigins: string[]): Promise<void> {
  await context.route('**/*', async route => {
    try {
      await validateDestination(route.request().url(), allowedOrigins);
      // 自動リダイレクトは検査を迂回するため、プロトタイプでは拒否する。
      const response = await route.fetch({ maxRedirects: 0, timeout: 30000 });
      try {
        if (response.status() >= 300 && response.status() < 400 && response.status() !== 304) {
          await route.abort('blockedbyclient');
          return;
        }
        await route.fulfill({ response });
      } finally { await response.dispose(); }
    } catch { await route.abort('blockedbyclient'); }
  });
  // WebSocketは撮影プロトタイプでは使用しない。
  await context.routeWebSocket('**/*', socket => socket.close());
}
