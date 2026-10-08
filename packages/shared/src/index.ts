export interface DeviceProfile {
  id: number;
  name: string;
  slug: string;
  viewport_width: number;
  viewport_height: number;
  user_agent?: string;
  device_scale_factor: number;
  is_mobile: boolean;
  has_touch: boolean;
}
export interface CaptureSettings {
  navigation_timeout_ms: number;
  image_timeout_ms: number;
  lazy_load: boolean;
  concurrency: number;
  pixel_threshold: number;
  review_threshold: number;
  changed_threshold: number;
  ignore_selectors: string[];
}
export interface PrototypeManifest {
  targets: { id: number; url: string; label: string }[];
  devices: DeviceProfile[];
  settings: CaptureSettings;
  allowed_origins: string[];
}
export const DEFAULT_SETTINGS: CaptureSettings = {
  navigation_timeout_ms: 30000, image_timeout_ms: 10000, lazy_load: true,
  concurrency: 2, pixel_threshold: 0.2, review_threshold: 0.001,
  changed_threshold: 0.01, ignore_selectors: [],
};
export const DEFAULT_DEVICES: DeviceProfile[] = [
  { id: 1, name: 'Desktop', slug: 'desktop', viewport_width: 1440, viewport_height: 900, device_scale_factor: 1, is_mobile: false, has_touch: false },
  { id: 2, name: 'Tablet', slug: 'tablet', viewport_width: 768, viewport_height: 1024, device_scale_factor: 1, is_mobile: false, has_touch: true },
  { id: 3, name: 'Mobile', slug: 'mobile', viewport_width: 390, viewport_height: 844, device_scale_factor: 1, is_mobile: true, has_touch: true },
];
export type DifferenceStatus = 'UNCHANGED' | 'REVIEW' | 'CHANGED';
export function differenceStatus(ratio: number, settings: Pick<CaptureSettings, 'review_threshold' | 'changed_threshold'>): DifferenceStatus {
  if (ratio >= settings.changed_threshold) return 'CHANGED';
  return ratio > settings.review_threshold ? 'REVIEW' : 'UNCHANGED';
}
