import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isPublicAddress, validateUrl, validateDestination } from '../dist/security/url-validator.js';
import { parseManifest } from '../dist/config.js';
import { readFileSync } from 'node:fs';
const manifest = () => JSON.parse(readFileSync(new URL('../../../docs/prototype-manifest.example.json', import.meta.url)));
test('IPv4・IPv6の非公開アドレスとmetadataを拒否する', () => {
  for (const address of ['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.0.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1']) assert.equal(isPublicAddress(address), false, address);
  assert.equal(isPublicAddress('8.8.8.8'), true);
});
test('許可Origin、スキーム、URL内の認証情報を検査する', () => {
  const origins = ['https://example.com'];
  assert.equal(validateUrl('https://example.com/path', origins).origin, origins[0]);
  for (const url of ['https://evil.example', 'http://example.com', 'file:///etc/passwd', 'https://user:pass@example.com']) assert.throws(() => validateUrl(url, origins));
  assert.throws(() => validateUrl('http://localhost', ['http://localhost']));
});
test('DNSが公開IPと非公開IPを返した場合は拒否する', async () => {
  await assert.rejects(validateDestination('https://example.com', ['https://example.com'], async () => [{ address: '8.8.8.8' }, { address: '10.0.0.1' }]));
  await assert.rejects(validateDestination('https://example.com', ['https://example.com'], async () => []));
});
test('Schemaに加えてID・閾値・Origin・パストラバーサルを検査する', () => {
  assert.equal(parseManifest(manifest()).targets.length, 1);
  for (const mutate of [
    value => value.devices[0].slug = '../outside',
    value => value.targets.push(value.targets[0]),
    value => value.settings.review_threshold = 0.5,
    value => value.allowed_origins = ['https://example.com/path'],
    value => value.settings.concurrency = 100,
    value => value.targets[0].url = 'https://outside.example',
  ]) { const value = manifest(); mutate(value); assert.throws(() => parseManifest(value)); }
});
