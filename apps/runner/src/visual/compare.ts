import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';
import { differenceStatus, type CaptureSettings } from '@odvr/shared';
import { decodeImage, normalizeImage } from './normalize-image.js';
export function compareImages(baselineBuffer: Buffer, currentBuffer: Buffer, settings: CaptureSettings) {
  const baseline = decodeImage(baselineBuffer), current = decodeImage(currentBuffer);
  const width = Math.max(baseline.width, current.width), height = Math.max(baseline.height, current.height);
  const before = normalizeImage(baseline, width, height), after = normalizeImage(current, width, height);
  // 寸法差の領域はpixelmatchの判定から外し、後で必ず差分へ加算する。
  for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) {
    if ((x < baseline.width && y < baseline.height) === (x < current.width && y < current.height)) continue;
    const offset = (y * width + x) * 4;
    before.data.set(after.data.subarray(offset, offset + 4), offset);
  }
  const diff = new PNG({ width, height });
  let diffPixels = pixelmatch(before.data, after.data, diff.data, width, height, { threshold: settings.pixel_threshold });
  // 寸法の違いは背景色にかかわらず差分として数える。
  for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) {
    const inBefore = x < baseline.width && y < baseline.height;
    const inAfter = x < current.width && y < current.height;
    if (inBefore === inAfter) continue;
    const offset = (y * width + x) * 4;
    diffPixels++;
    diff.data.set([255, 0, 0, 255], offset);
  }
  const ratio = diffPixels / (width * height);
  return {
    status: differenceStatus(ratio, settings), width: current.width, height: current.height,
    baseline_width: baseline.width, baseline_height: baseline.height,
    dimension_changed: baseline.width !== current.width || baseline.height !== current.height,
    diff_pixels: diffPixels, total_pixels: width * height, diff_ratio: ratio,
    diff_image: PNG.sync.write(diff),
  };
}
