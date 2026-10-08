import { SnapshotError, classifyError } from '../errors.js';
import type { Page } from 'playwright';
import type { CaptureSettings } from '@odvr/shared';
export async function stabilizePage(page: Page, settings: CaptureSettings): Promise<void> {
  await page.addStyleTag({ content: '* { animation: none !important; transition: none !important; scroll-behavior: auto !important; }' });
  try {
    await page.waitForFunction(() => document.fonts.status === 'loaded', { }, { timeout: settings.image_timeout_ms });
    if (settings.lazy_load) {
      await page.evaluate(async timeout => {
        const started = Date.now();
        let position = 0;
        while (position + window.innerHeight < document.documentElement.scrollHeight) {
          if (Date.now() - started > timeout) throw new Error('Lazy Loadの待機時間を超えました');
          position += Math.max(1, Math.floor(window.innerHeight * 0.75));
          window.scrollTo(0, position);
          await new Promise(resolve => setTimeout(resolve, 50));
        }
        window.scrollTo(0, 0);
      }, settings.image_timeout_ms);
    }
    await page.waitForFunction(() => Array.from(document.images).every(image => image.complete), {}, { timeout: settings.image_timeout_ms });
    const broken = await page.evaluate(() => Array.from(document.images).some(image => image.currentSrc && image.naturalWidth === 0));
    if (broken) throw new SnapshotError('IMAGE_LOAD_FAILED');
  } catch (error) { throw classifyError(error, 'IMAGE_LOAD_FAILED'); }
}
