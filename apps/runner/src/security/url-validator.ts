import { lookup } from 'node:dns/promises';
import ipaddr from 'ipaddr.js';
import { SnapshotError, classifyError } from '../errors.js';
export type Resolver = (hostname: string) => Promise<{ address: string }[]>;
const resolve: Resolver = hostname => lookup(hostname, { all: true });
export function isPublicAddress(address: string): boolean {
  try {
    if (address.includes('%')) return false;
    const parsed = ipaddr.process(address);
    if (parsed.range() !== 'unicast') return false;
    if (parsed.kind() === 'ipv6') {
      // 公開IPv6の経路だけ。特殊割当・新しい文書用範囲も拒否する。
      return parsed.match(ipaddr.parseCIDR('2000::/3')) && !parsed.match(ipaddr.parseCIDR('2001::/23')) && !parsed.match(ipaddr.parseCIDR('3fff::/20'));
    }
    return true;
  } catch { return false; }
}
export function validateUrl(value: string, allowedOrigins: string[], localAddress?: string): URL {
  if (Buffer.byteLength(value) > 8192 || /[\x00-\x20\x7f\\]/.test(value) || /%(?![0-9a-f]{2})/i.test(value)) throw new SnapshotError('URL_BLOCKED');
  let url: URL;
  try { url = new URL(value); } catch { throw new SnapshotError('URL_BLOCKED'); }
  if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password || /^(?:https?):\/\/[^/]*@/i.test(value) || url.port === '0') throw new SnapshotError('URL_BLOCKED');
  if (!allowedOrigins.includes(url.origin)) throw new SnapshotError('ORIGIN_BLOCKED');
  const hostname = url.hostname.replace(/^\[|\]$/g, '');
  if (hostname === 'localhost' || hostname.endsWith('.localhost') || hostname === 'metadata.google.internal') throw new SnapshotError('IP_BLOCKED');
  if (ipaddr.isValid(hostname) && !isPublicAddress(hostname) && !(localAddress === hostname && ipaddr.process(hostname).kind() === 'ipv4' && ipaddr.process(hostname).range() === 'private')) throw new SnapshotError('IP_BLOCKED');
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
