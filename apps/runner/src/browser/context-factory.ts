import type { Browser } from 'playwright';
import type { DeviceProfile } from '@odvr/shared';
export function createContext(browser: Browser, device: DeviceProfile, authOrigin?: string) {
  const username = process.env.ODVR_HTTP_AUTH_USER, password = process.env.ODVR_HTTP_AUTH_PASSWORD;
  if (Boolean(username) !== Boolean(password)) throw new Error('Basic認証はユーザー名とパスワードの両方を指定してください');
  if (username && !authOrigin) throw new Error('Basic認証の対象Originを指定してください');
  return browser.newContext({
    viewport: { width: device.viewport_width, height: device.viewport_height },
    deviceScaleFactor: device.device_scale_factor, isMobile: device.is_mobile,
    hasTouch: device.has_touch, userAgent: device.user_agent,
    httpCredentials: username && password ? { username, password, origin: authOrigin } : undefined,
    serviceWorkers: 'block', acceptDownloads: false,
  });
}
