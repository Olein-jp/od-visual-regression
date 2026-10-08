import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PNG } from 'pngjs';
import { DEFAULT_SETTINGS, differenceStatus } from '@odvr/shared';
import { compareImages } from '../dist/visual/compare.js';
function image(width, height, color) {
  const png = new PNG({ width, height });
  for (let offset = 0; offset < png.data.length; offset += 4) png.data.set(color, offset);
  return PNG.sync.write(png);
}
test('同一画像と全面変更の差分率', () => {
  const white = image(10, 10, [255, 255, 255, 255]);
  assert.equal(compareImages(white, white, DEFAULT_SETTINGS).diff_ratio, 0);
  const changed = compareImages(white, image(10, 10, [0, 0, 0, 255]), DEFAULT_SETTINGS);
  assert.equal(changed.diff_pixels, 100);
  assert.equal(changed.status, 'CHANGED');
});
test('透明または白い追加領域も寸法差として数える', () => {
  for (const color of [[255, 255, 255, 255], [0, 0, 0, 0]]) {
    const result = compareImages(image(2, 2, color), image(2, 3, color), DEFAULT_SETTINGS);
    assert.equal(result.dimension_changed, true);
    assert.equal(result.diff_pixels, 2);
    assert.equal(result.total_pixels, 6);
    assert.equal(PNG.sync.read(result.diff_image).height, 3);
  }
});
test('縦長と横長の画像は包含キャンバスで比較する', () => {
  const result = compareImages(image(2, 3, [0, 0, 0, 255]), image(3, 2, [0, 0, 0, 255]), DEFAULT_SETTINGS);
  assert.equal(result.total_pixels, 9);
  assert.equal(result.diff_pixels, 4);
});
test('判定境界は0.1%以下・1%未満・1%以上', () => {
  assert.equal(differenceStatus(0.001, DEFAULT_SETTINGS), 'UNCHANGED');
  assert.equal(differenceStatus(0.00101, DEFAULT_SETTINGS), 'REVIEW');
  assert.equal(differenceStatus(0.01, DEFAULT_SETTINGS), 'CHANGED');
});
test('不正PNGと過大寸法を拒否する', () => {
  assert.throws(() => compareImages(Buffer.from('invalid'), Buffer.from('invalid'), DEFAULT_SETTINGS));
  const oversized = image(1, 1, [0, 0, 0, 0]);
  oversized.writeUInt32BE(100000, 16);
  oversized.writeUInt32BE(100000, 20);
  assert.throws(() => compareImages(oversized, oversized, DEFAULT_SETTINGS), /上限/);
});
