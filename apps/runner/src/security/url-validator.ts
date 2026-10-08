import { lookup } from 'node:dns/promises';
import ipaddr from 'ipaddr.js';
import { SnapshotError, classifyError } from '../errors.js';
export type Resolver = (hostname: string) => Promise<{ address: string }[]>;
const resolve: Resolver = hostname => lookup(hostname, { all: true });
export function isPublicAddress(address: string): boolean {
  try {
    const parsed = ipaddr.process(address);
    return parsed.range() === 'unicast';
  } catch { return false; }
}
export function validateUrl(value: string, allowedOrigins: string[]): URL {
  let url: URL;
  try { url = new URL(value); } catch { throw new SnapshotError('URL_BLOCKED'); }
  if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password) throw new SnapshotError('URL_BLOCKED');
  if (!allowedOrigins.includes(url.origin)) throw new SnapshotError('ORIGIN_BLOCKED');
  const hostname = url.hostname.replace(/^\[|\]$/g, '');
  if (hostname === 'localhost' || hostname.endsWith('.localhost') || hostname === 'metadata.google.internal') throw new SnapshotError('IP_BLOCKED');
  if (ipaddr.isValid(hostname) && !isPublicAddress(hostname)) throw new SnapshotError('IP_BLOCKED');
  return url;
}
export async function validateDestination(value: string, allowedOrigins: string[], resolver: Resolver = resolve): Promise<URL> {
  const url = validateUrl(value, allowedOrigins);
  let addresses: { address: string }[];
  try { addresses = await resolver(url.hostname.replace(/^\[|\]$/g, '')); } catch (error) { throw classifyError(error, 'DNS_ERROR'); }
  if (!addresses.length) throw new SnapshotError('DNS_ERROR');
  if (addresses.some(item => !isPublicAddress(item.address))) throw new SnapshotError('IP_BLOCKED');
  return url;
}
