import { readdir, readFile } from 'node:fs/promises';
import Ajv from 'ajv';
import addFormats from 'ajv-formats';
const ajv = new Ajv({ strict: true });
addFormats(ajv);
for (const file of await readdir(new URL('../packages/schemas/src/', import.meta.url))) {
  const schema = JSON.parse(await readFile(new URL(`../packages/schemas/src/${file}`, import.meta.url), 'utf8'));
  ajv.compile(schema);
  console.log(`${file}: OK`);
}
