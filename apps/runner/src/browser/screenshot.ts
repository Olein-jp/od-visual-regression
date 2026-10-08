import { classifyError } from '../errors.js';
import type { Page } from 'playwright';
import type { CaptureSettings } from '@odvr/shared';
import { stabilizePage } from './stabilize-page.js';
export async function capturePage(page: Page, settings: CaptureSettings): Promise<Buffer> {
  await stabilizePage(page, settings);
  try {
    return await page.screenshot({ fullPage: true, type: 'png', scale: 'css', animations: 'disabled', caret: 'hide',
      timeout: settings.navigation_timeout_ms,
      mask: ['[data-odvr-ignore]', ...settings.ignore_selectors].map(selector => page.locator(selector)),
    });
  } catch (error) { throw classifyError(error, 'SCREENSHOT_FAILED'); }
}
