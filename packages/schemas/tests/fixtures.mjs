import { readFileSync, writeFileSync } from 'node:fs';
import { schemas } from '../validate.mjs';
export const fixtureURL = new URL('../../../wordpress/od-visual-regression/tests/contract-fixtures.json', import.meta.url);
export const fixtures = JSON.parse(readFileSync(fixtureURL, 'utf8'));
// 正常例からネストした必須・型・範囲の不正例を生成し、両言語へ同じJSONを渡す。
function* mutations(schema, value, path = []) {
  if (value && !Array.isArray(value) && typeof value === 'object') {
    for (const key of schema.required ?? []) yield { path: [...path, key], remove: true };
    if (schema.additionalProperties === false) yield { path: [...path, 'unexpected'], value: true };
    for (const [key, child] of Object.entries(value)) if (schema.properties?.[key]) yield* mutations(schema.properties[key], child, [...path, key]);
  } else if (Array.isArray(value)) {
    for (const [index, child] of value.entries()) yield* mutations(schema.items, child, [...path, index]);
  } else if (typeof value === 'string' && schema.maxLength) {
    yield { path, value: 'あ'.repeat(schema.maxLength + 1) };
  } else if (typeof value === 'number') {
    if (['integer','number'].includes(schema.type)) yield { path, value: String(value) };
    if (schema.minimum !== undefined) yield { path, value: schema.minimum - 1 };
    if (schema.maximum !== undefined) yield { path, value: schema.maximum + 1 };
  }
}
for (const fixture of [...fixtures].filter(x => x.valid && x.name.endsWith(' 正常'))) {
  for (const mutation of mutations(schemas[fixture.schema], fixture.value)) {
    const value = structuredClone(fixture.value);
    let parent = value;
    for (const key of mutation.path.slice(0, -1)) parent = parent[key];
    if (mutation.remove) delete parent[mutation.path.at(-1)];
    else parent[mutation.path.at(-1)] = mutation.value;
    fixtures.push({ ...fixture, name: `${fixture.schema} 境界 ${mutation.path.join('.')} ${mutation.remove ? '欠落' : JSON.stringify(mutation.value).slice(0, 30)}`, value, valid: false });
  }
}
export function writeSharedFixtures() {
  writeFileSync(new URL('../../../wordpress/od-visual-regression/tests/contract-fixtures.generated.json', import.meta.url), JSON.stringify(fixtures));
}
