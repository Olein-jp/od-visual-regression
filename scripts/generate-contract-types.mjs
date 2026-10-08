import { readFileSync, writeFileSync } from 'node:fs';
import ts from 'typescript';
import { schemas } from '../packages/schemas/validate.mjs';

function typeFor(schema) {
  if (Object.hasOwn(schema, 'const')) return JSON.stringify(schema.const);
  if (schema.enum) return schema.enum.map(x => JSON.stringify(x)).join(' | ');
  if (schema.anyOf) return schema.anyOf.map(typeFor).join(' | ');
  if (schema.type === 'object') return `{ ${Object.entries(schema.properties).map(([key, child]) => `${key}${schema.required.includes(key) ? '' : '?'}: ${typeFor(child)}`).join('; ')} }`;
  if (schema.type === 'array') return `Array<${typeFor(schema.items)}>`;
  return { string:'string', integer:'number', number:'number', boolean:'boolean', null:'null' }[schema.type];
}
const versions = ['COMMUNICATION_SCHEMA_VERSION','SETTINGS_JSON_VERSION','ENVIRONMENT_JSON_VERSION','SNAPSHOT_METADATA_VERSION','DATABASE_VERSION'];
const source = '// 製品APIの契約。変更後は node scripts/generate-contract-types.mjs でSchemaと同期する。\n'
  + versions.map(name => `export const ${name} = 1 as const;`).join('\n') + '\n'
  + Object.entries(schemas).filter(([name]) => !['prototype-manifest','device-profile'].includes(name)).sort(([a],[b]) => a.localeCompare(b)).map(([name,schema]) => `export type ${name.split('-').map(x => x[0].toUpperCase()+x.slice(1)).join('')} = ${typeFor(schema)};`).join('\n') + '\n';
const formatted = ts.createPrinter({ newLine:ts.NewLineKind.LineFeed }).printFile(ts.createSourceFile('contracts.ts',source,ts.ScriptTarget.Latest,true,ts.ScriptKind.TS));
const path = new URL('../packages/shared/src/contracts.ts', import.meta.url);
if (process.argv.includes('--check')) {
  if (readFileSync(path,'utf8') !== formatted) throw new Error('共通型がSchemaと一致しません。node scripts/generate-contract-types.mjs を実行してください。');
} else writeFileSync(path, formatted);
