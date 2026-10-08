import { constants } from 'node:fs';
import { open } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { isAbsolute, join } from 'node:path';
import { SecretManagerServiceClient } from '@google-cloud/secret-manager';
import { DestinationPolicy, type DestinationPolicyOptions } from '../security/destination-policy.js';

export class JobConfigurationError extends Error {
  constructor(){super('Jobの登録設定または秘密の取得を確認してください。');}
}
interface Registration {
  schema_version:1;
  profile:'cloud'|'local';
  sites:Record<string,{callback_base:string}>;
  secret_project?:string;
  local_secret_directory?:string;
  local_destination?:DestinationPolicyOptions['localDestination'];
  report_directory?:string;
}
export async function readPrivateFile(path:string,maxBytes:number,secret=false):Promise<Buffer> {
  if(!isAbsolute(path)) throw new JobConfigurationError();
  const handle=await open(path,constants.O_RDONLY|constants.O_NOFOLLOW);
  try {
    const stat=await handle.stat();
    if(!stat.isFile() || stat.size>maxBytes || (stat.mode & (secret ? 0o077:0o022)) || (stat.uid!==0 && stat.uid!==process.getuid?.())) throw new JobConfigurationError();
    const buffer=Buffer.alloc(maxBytes+1);const {bytesRead}=await handle.read(buffer,0,buffer.length,0);
    if(bytesRead>maxBytes) throw new JobConfigurationError();
    return buffer.subarray(0,bytesRead);
  } finally { await handle.close(); }
}
export function runSecretName(siteId:string,runUuid:string):string {
  return `odvr-run-${createHash('sha256').update(siteId).digest('hex').slice(0,24)}-${runUuid}`;
}
export function crc32c(data:Uint8Array):number {
  let crc=0xffffffff;
  for(const byte of data) { crc^=byte;for(let i=0;i<8;i++) crc=(crc>>>1)^((crc&1) ? 0x82f63b78:0); }
  return (crc^0xffffffff)>>>0;
}
export async function cloudRunToken(resource:string):Promise<string> {
  const client=new SecretManagerServiceClient({apiEndpoint:'secretmanager.googleapis.com'});
  try {
    const [value]=await client.accessSecretVersion({name:resource},{timeout:10000,retry:null});
    const data=value.payload?.data;
    const buffer=typeof data==='string' ? Buffer.from(data,'base64') : data ? Buffer.from(data) : Buffer.alloc(0);
    if(value.name!==resource || buffer.length!==43 || value.payload?.dataCrc32c?.toString()!==String(crc32c(buffer))) throw new JobConfigurationError();
    try { const token=buffer.toString('utf8');if(!/^[A-Za-z0-9_-]{43}$/.test(token)) throw new JobConfigurationError();return token; }
    finally { buffer.fill(0); }
  } catch { throw new JobConfigurationError(); }
  finally { await client.close().catch(()=>{}); }
}
/** 設定ファイルは運用者がread-onlyで配置し、HTTP/CLIからcallback・秘密を選ばせない。 */
export async function loadJob(environment:NodeJS.ProcessEnv=process.env,secretReader=cloudRunToken) {
  try {
    const registration:Registration=JSON.parse((await readPrivateFile(environment.ODVR_JOB_CONFIG ?? '/etc/odvr/runner.json',65536)).toString('utf8'));
    const allowed=['schema_version','profile','sites','secret_project','local_secret_directory','local_destination','report_directory'];
    if(!registration || registration.schema_version!==1 || !['cloud','local'].includes(registration.profile) || Object.keys(registration).some(key=>!allowed.includes(key)) || !registration.sites || Array.isArray(registration.sites)) throw new JobConfigurationError();
    const siteId=environment.ODVR_SITE_ID ?? '';const runUuid=environment.ODVR_RUN_UUID ?? '';
    const site=Object.hasOwn(registration.sites,siteId) ? registration.sites[siteId] : undefined;
    if(!/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/.test(siteId) || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(runUuid) || !site || Object.keys(site).join()!=='callback_base') throw new JobConfigurationError();
    if(environment.ODVR_CALLBACK_BASE!==undefined && environment.ODVR_CALLBACK_BASE!==site.callback_base) throw new JobConfigurationError();
    const cloud=registration.profile==='cloud';
    if(cloud && ['GOOGLE_SDK_NODE_LOGGING','NODE_DEBUG','GRPC_TRACE','GRPC_VERBOSITY'].some(key=>environment[key])) throw new JobConfigurationError();
    if(cloud && (environment.CLOUD_RUN_TASK_INDEX!=='0' || environment.CLOUD_RUN_TASK_COUNT!=='1' || !environment.CLOUD_RUN_EXECUTION || environment.GOOGLE_APPLICATION_CREDENTIALS)) throw new JobConfigurationError();
    if(!cloud && (environment.K_SERVICE || environment.CLOUD_RUN_JOB || environment.CLOUD_RUN_EXECUTION)) throw new JobConfigurationError();
    const executionId=cloud ? environment.CLOUD_RUN_EXECUTION! : environment.ODVR_LOCAL_EXECUTION_ID ?? '';
    if(!/^[A-Za-z0-9][A-Za-z0-9_-]{0,199}$/.test(executionId)) throw new JobConfigurationError();
    const expiry=environment.ODVR_TOKEN_EXPIRES_AT ?? '';
    if(!/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{3})?Z$/.test(expiry)) throw new JobConfigurationError();
    const tokenExpiresAt=Date.parse(expiry);
    if(!Number.isFinite(tokenExpiresAt) || tokenExpiresAt<=Date.now() || tokenExpiresAt>Date.now()+5400000) throw new JobConfigurationError();
    new DestinationPolicy({controlBase:site.callback_base,captureOrigins:!cloud && registration.local_destination ? [registration.local_destination.origin]:[],profile:registration.profile,localDestination:registration.local_destination});
    let token:string;
    if(cloud) {
      if(!/^[1-9][0-9]{5,19}$/.test(registration.secret_project ?? '') || registration.local_destination || registration.local_secret_directory) throw new JobConfigurationError();
      const expected=`projects/${registration.secret_project}/secrets/${runSecretName(siteId,runUuid)}/versions/`;
      const resource=environment.ODVR_RUN_SECRET_VERSION ?? '';
      if(!resource.startsWith(expected) || !/^[1-9][0-9]{0,9}$/.test(resource.slice(expected.length))) throw new JobConfigurationError();
      token=await secretReader(resource);
    } else {
      if(registration.secret_project || environment.ODVR_RUN_SECRET_VERSION || !registration.local_secret_directory || !isAbsolute(registration.local_secret_directory)) throw new JobConfigurationError();
      const bytes=await readPrivateFile(join(registration.local_secret_directory,runSecretName(siteId,runUuid)),43,true);
      try { token=bytes.toString('utf8'); } finally { bytes.fill(0); }
    }
    if(!/^[A-Za-z0-9_-]{43}$/.test(token)) throw new JobConfigurationError();
    if(registration.report_directory && !isAbsolute(registration.report_directory)) throw new JobConfigurationError();
    // 非秘密設定とTokenは呼び出し元のメモリ内だけへ返す。
    return {client:{callbackBase:site.callback_base,runUuid,executionId,token,tokenExpiresAt,profile:registration.profile,localDestination:registration.local_destination},run:{executionId,profile:registration.profile,localDestination:registration.local_destination,reportPath:registration.report_directory ? join(registration.report_directory,`${runUuid}-${executionId}.json`):undefined}};
  } catch { throw new JobConfigurationError(); }
}
