import { lookup } from 'node:dns/promises';
import ipaddr from 'ipaddr.js';
export type Resolver = (hostname: string) => Promise<{ address: string }[]>;
const resolve: Resolver = hostname => lookup(hostname, { all: true });
export function isPublicAddress(address: string): boolean {
  try {
    const parsed = ipaddr.process(address);
    return parsed.range() === 'unicast';
  } catch { return false; }
}
export function validateUrl(value: string, allowedOrigins: string[]): URL {
  const url = new URL(value);
  if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password) throw new Error('許可されないURL形式です');
  if (!allowedOrigins.includes(url.origin)) throw new Error('許可されないOriginです');
  const hostname = url.hostname.replace(/^\[|\]$/g, '');
  if (hostname === 'localhost' || hostname.endsWith('.localhost') || hostname === 'metadata.google.internal') throw new Error('内部ホストへのアクセスは禁止です');
  if (ipaddr.isValid(hostname) && !isPublicAddress(hostname)) throw new Error('非公開IPへのアクセスは禁止です');
  return url;
}
export async function validateDestination(value: string, allowedOrigins: string[], resolver: Resolver = resolve): Promise<URL> {
  const url = validateUrl(value, allowedOrigins);
  const addresses = await resolver(url.hostname.replace(/^\[|\]$/g, ''));
  if (!addresses.length || addresses.some(item => !isPublicAddress(item.address))) throw new Error('DNS解決先に非公開IPが含まれています');
  return url;
}
