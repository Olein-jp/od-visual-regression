import type { Browser, LaunchOptions } from 'playwright';
import type { DeviceProfile } from '@odvr/shared';
import type { TransportAuth } from '../security/pinned-http-client.js';

// route外の不要な通信を減らす。プロセスの完全隔離はCloud Runの多層防御で検証する。
export const BROWSER_LAUNCH_OPTIONS: LaunchOptions = { args: [
  '--disable-background-networking', '--disable-component-update', '--disable-domain-reliability',
  '--disable-quic', '--dns-prefetch-disable', '--host-resolver-rules=MAP * ~NOTFOUND',
  '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
  '--disable-features=Prerender2,SpeculationRulesPrefetch,MediaRouter',
] };
export function captureAuth(origin: string): TransportAuth | undefined {
  const username = process.env.ODVR_HTTP_AUTH_USER, password = process.env.ODVR_HTTP_AUTH_PASSWORD;
  if (Boolean(username) !== Boolean(password)) throw new Error('Basic認証はユーザー名とパスワードの両方を指定してください');
  if (!username || !password) return undefined;
  if (new URL(origin).origin !== origin || new URL(origin).protocol !== 'https:') throw new Error('Basic認証の対象はHTTPS Originに限定してください');
  return {kind:'basic',origin,username,password};
}
export async function createContext(browser: Browser, device: DeviceProfile) {
  const context = await browser.newContext({
    viewport: { width: device.viewport_width, height: device.viewport_height },
    deviceScaleFactor: device.device_scale_factor, isMobile: device.is_mobile,
    hasTouch: device.has_touch, userAgent: device.user_agent,
    serviceWorkers: 'block', acceptDownloads: false,
  });
  await context.addInitScript(() => {
    // popup/iframeにもページスクリプトより先に適用する。認証情報は渡さない。
    for (const key of ['RTCPeerConnection','webkitRTCPeerConnection','RTCDataChannel','WebTransport']) {
      Object.defineProperty(globalThis,key,{value:undefined,writable:false,configurable:false});
    }
  });
  return context;
}
