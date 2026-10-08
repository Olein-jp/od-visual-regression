import { lookup } from 'node:dns/promises';
import { isIP } from 'node:net';
import ipaddr from 'ipaddr.js';
import { SnapshotError } from '../errors.js';
import { isPublicAddress, validateUrl, type Resolver } from './url-validator.js';

export type Purpose = 'capture' | 'manifest' | 'credentials' | 'baseline' | 'upload' | 'progress' | 'complete';
export interface PinnedDestination {
  readonly origin: string;
  readonly address: string;
  readonly family: 4 | 6;
  readonly port: number;
  readonly resolvedAt: number;
  readonly purpose: Purpose;
}
export interface DestinationPolicyOptions {
  captureOrigins: readonly string[];
  controlBase?: string;
  profile?: 'cloud' | 'local';
  localDestination?: Readonly<{ origin: string; address: string; port: number }>;
}
export function normalizeAddress(address: string): string {
  if (address.includes('%') || !isIP(address)) throw new SnapshotError('IP_BLOCKED');
  return ipaddr.process(address).toString();
}
function parseUrl(value:string):URL {
  try { return new URL(value); } catch { throw new SnapshotError('URL_BLOCKED'); }
}
export class DestinationPolicy {
  readonly captureOrigins: readonly string[];
  readonly controlBase?: string;
  readonly localDestination?: Readonly<{origin: string; address: string; port: number}>;
  constructor(options: DestinationPolicyOptions) {
    if (!['cloud','local'].includes(options.profile ?? 'cloud') || options.captureOrigins.length > 30 || new Set(options.captureOrigins).size !== options.captureOrigins.length) throw new SnapshotError('ORIGIN_BLOCKED');
    this.captureOrigins = Object.freeze([...options.captureOrigins]);
    for (const origin of this.captureOrigins) if (parseUrl(origin).origin !== origin || !['http:','https:'].includes(parseUrl(origin).protocol)) throw new SnapshotError('ORIGIN_BLOCKED');
    if (options.controlBase) {
      const url = parseUrl(options.controlBase);
      if (url.search || url.hash || url.username || url.password || !url.pathname.endsWith('/runner') || url.protocol !== 'https:') {
        // 開発専用の単一HTTP先だけは後で検査する。
        if (!(options.profile === 'local' && options.localDestination?.origin === url.origin && url.protocol === 'http:' && !url.search && !url.hash && !url.username && !url.password && url.pathname.endsWith('/runner'))) throw new SnapshotError('URL_BLOCKED');
      }
      this.controlBase = url.href;
    }
    const cloudRuntime = Boolean(process.env.K_SERVICE || process.env.CLOUD_RUN_JOB || process.env.CLOUD_RUN_EXECUTION);
    if ((options.profile ?? 'cloud') !== 'local') {
      if (options.localDestination !== undefined) throw new SnapshotError('IP_BLOCKED');
    } else {
      if (cloudRuntime || !options.localDestination) throw new SnapshotError('IP_BLOCKED');
      const {origin,address,port} = options.localDestination;
      const url = parseUrl(origin);
      if (!this.captureOrigins.includes(origin) || url.hostname === 'localhost' || url.hostname.endsWith('.localhost') || url.hostname === 'metadata.google.internal') throw new SnapshotError('ORIGIN_BLOCKED');
      let parsed: ReturnType<typeof ipaddr.parse>;
      try { parsed = ipaddr.parse(address); } catch { throw new SnapshotError('IP_BLOCKED'); }
      if (url.origin !== origin || !['http:','https:'].includes(url.protocol) || url.username || url.password || !Number.isInteger(port) || port < 1 || port > 65535 || Number(url.port || (url.protocol === 'https:' ? 443 : 80)) !== port || parsed.kind() !== 'ipv4' || parsed.range() !== 'private' || normalizeAddress(address) !== address) throw new SnapshotError('IP_BLOCKED');
      this.localDestination = Object.freeze({origin,address,port});
    }
    Object.freeze(this);
  }
  validate(value: string, purpose: Purpose): URL {
    let origins: string[];
    if (purpose === 'capture') origins = [...this.captureOrigins];
    else {
      if (!this.controlBase || !['manifest','credentials','baseline','upload','progress','complete'].includes(purpose)) throw new SnapshotError('ORIGIN_BLOCKED');
      origins = [parseUrl(this.controlBase).origin];
    }
    let candidate:URL;
    try { candidate = parseUrl(value); } catch { throw new SnapshotError('URL_BLOCKED'); }
    const url = validateUrl(value, origins, this.localDestination?.origin === candidate.origin ? this.localDestination.address : undefined);
    if (purpose !== 'capture') {
      const base = parseUrl(this.controlBase!);
      if (url.protocol !== 'https:' && this.localDestination?.origin !== url.origin) throw new SnapshotError('URL_BLOCKED');
      const prefix = base.pathname;
      const run = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
      const paths: Record<Exclude<Purpose,'capture'>,string> = {
        manifest:`/runs/${run}/manifest`, credentials:`/runs/${run}/credentials`, baseline:'/snapshots/[1-9][0-9]{0,9}/baseline', upload:`/runs/${run}/snapshots`, progress:`/runs/${run}/progress`, complete:`/runs/${run}/complete`,
      };
      if (!url.pathname.startsWith(prefix) || !new RegExp(`^${paths[purpose]}$`).test(url.pathname.slice(prefix.length)) || url.search || url.hash) throw new SnapshotError('URL_BLOCKED');
    }
    return url;
  }
  async resolve(value: string, purpose: Purpose, resolver: Resolver = hostname => lookup(hostname,{all:true})): Promise<PinnedDestination> {
    const url = this.validate(value,purpose);
    const port = Number(url.port || (url.protocol === 'https:' ? 443 : 80));
    if (this.localDestination?.origin === url.origin) return Object.freeze({origin:url.origin,address:this.localDestination.address,family:4,port,resolvedAt:Date.now(),purpose});
    const hostname = url.hostname.replace(/^\[|\]$/g,'');
    let answers: {address:string}[];
    try { answers = isIP(hostname) ? [{address:hostname}] : await resolver(hostname); }
    catch { throw new SnapshotError('DNS_ERROR'); }
    if (!Array.isArray(answers) || !answers.length || answers.length > 32) throw new SnapshotError('DNS_ERROR');
    if (answers.some(x => !x || typeof x.address !== 'string' || !isIP(x.address) || !isPublicAddress(x.address))) throw new SnapshotError('IP_BLOCKED');
    const address = normalizeAddress(answers[0].address);
    return Object.freeze({origin:url.origin,address,family:isIP(address) as 4|6,port,resolvedAt:Date.now(),purpose});
  }
}
