import { PNG } from 'pngjs';
export const MAX_PIXELS = 40_000_000;
export function decodeImage(buffer: Buffer): PNG {
  // PNG展開前に寸法を検査し、過大なメモリ確保を防ぐ。
  if (buffer.length < 24 || buffer.subarray(0, 8).toString('hex') !== '89504e470d0a1a0a') throw new Error('PNG形式ではありません');
  const width = buffer.readUInt32BE(16), height = buffer.readUInt32BE(20);
  if (!width || !height || width * height > MAX_PIXELS) throw new Error('画像寸法が上限を超えています');
  return PNG.sync.read(buffer);
}
export function normalizeImage(source: PNG, width: number, height: number): PNG {
  if (width * height > MAX_PIXELS) throw new Error('比較画像が上限を超えています');
  const output = new PNG({ width, height });
  // 不足領域は透明色で補完する。
  output.data.fill(0);
  PNG.bitblt(source, output, 0, 0, source.width, source.height, 0, 0);
  return output;
}
