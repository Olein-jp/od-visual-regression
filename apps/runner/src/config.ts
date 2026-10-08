import { readFileSync } from 'node:fs';
import { Ajv } from 'ajv';
import addFormats from 'ajv-formats';
import type { PrototypeManifest } from '@odvr/shared';
import { validateUrl } from './security/url-validator.js';
const ajv = new Ajv({ allErrors: true });
addFormats.default(ajv);
const schema = JSON.parse(readFileSync(new URL('../../../packages/schemas/src/prototype-manifest.schema.json', import.meta.url), 'utf8'));
const validate = ajv.compile<PrototypeManifest>(schema);
export function parseManifest(input: unknown): PrototypeManifest {
  if (!validate(input)) throw new Error(`Manifestが不正です: ${ajv.errorsText(validate.errors)}`);
  if (input.settings.review_threshold >= input.settings.changed_threshold) throw new Error('差分判定の閾値の順序が不正です');
  for (const origin of input.allowed_origins) {
    if (new URL(origin).origin !== origin) throw new Error('allowed_originsにはOriginのみを指定してください');
    validateUrl(origin, input.allowed_origins);
  }
  for (const target of input.targets) validateUrl(target.url, input.allowed_origins);
  for (const list of [input.targets, input.devices]) if (new Set(list.map(item => item.id)).size !== list.length) throw new Error('IDが重複しています');
  if (new Set(input.devices.map(device => device.slug)).size !== input.devices.length) throw new Error('Deviceのslugが重複しています');
  return input;
}
