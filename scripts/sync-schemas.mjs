import { mkdir, readdir, copyFile, unlink } from 'node:fs/promises';
const source = new URL('../packages/schemas/src/', import.meta.url);
const destination = new URL('../wordpress/od-visual-regression/schemas/', import.meta.url);
await mkdir(destination, { recursive: true });
const names = await readdir(source);
for (const name of await readdir(destination)) if (name.endsWith('.schema.json') && !names.includes(name)) await unlink(new URL(name, destination));
for (const name of names) if (name.endsWith('.schema.json')) await copyFile(new URL(name, source), new URL(name, destination));
