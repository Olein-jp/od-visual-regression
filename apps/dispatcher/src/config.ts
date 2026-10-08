import { isAbsolute } from 'node:path';
import { readPrivateFile } from '@odvr/runner/jobs/job-config';
import { DestinationPolicy, type DestinationPolicyOptions } from '@odvr/runner/security/destination-policy';
import type { HmacSite } from './hmac.js';
export interface DispatcherConfig {
  schema_version:1;
  profile:'cloud'|'local';
  sites:Record<string,HmacSite>;
  firestore:{project:string;database:string};
  secret_project:string;
  job:{name:string;container:string;image:string;generation:string;runner_service_account:string};
  scheduler:{audience:string;subject:string;email:string};
  local?:{callback_destination:NonNullable<DestinationPolicyOptions['localDestination']>;firestore:{host:string;port:number};shared_directory:string;run_directory:string;queue_directory:string;worker_key_file:string};
}
const resource=/^projects\/[1-9][0-9]{5,19}\/secrets\/[A-Za-z0-9_-]{1,255}\/versions\/[1-9][0-9]{0,9}$/;
const exactKeys=(value:object,keys:string[])=>Object.keys(value).length===keys.length && Object.keys(value).every(key=>keys.includes(key));
export function checkedConfig(value:unknown,environment:NodeJS.ProcessEnv=process.env,now=Date.now()):DispatcherConfig {
  try {
    const config=value as DispatcherConfig;
    if(!config || !exactKeys(config,config.local ? ['schema_version','profile','sites','firestore','secret_project','job','scheduler','local']:['schema_version','profile','sites','firestore','secret_project','job','scheduler']) || config.schema_version!==1 || !['cloud','local'].includes(config.profile) || !config.sites || Array.isArray(config.sites) || Object.keys(config.sites).length<1 || Object.keys(config.sites).length>100)throw new Error();
    for(const [id,site] of Object.entries(config.sites)) {
      if(!/^[A-Za-z0-9_-]{1,100}$/.test(id) || !exactKeys(site,['callback_base','shared_versions','rotation_until']) || !Array.isArray(site.shared_versions) || site.shared_versions.length<1 || site.shared_versions.length>2 || new Set(site.shared_versions).size!==site.shared_versions.length || !site.shared_versions.every(item=>resource.test(item) && item.split('/')[1]!==config.secret_project) || (site.rotation_until!==null && (!Number.isSafeInteger(site.rotation_until) || site.rotation_until>now+600000)) || (site.shared_versions.length===2 && site.rotation_until===null))throw new Error();
      if(site.callback_base.length>2048)throw new Error();const url=new URL(site.callback_base);if(url.pathname!=='/wp-json/odvr/v1/runner' && !url.pathname.endsWith('/wp-json/odvr/v1/runner'))throw new Error();
      // SchemaのcallbackはlocalもHTTPS。単一local例外でも経路・Originを拡張しない。
      if(url.protocol!=='https:')throw new Error();
      new DestinationPolicy({captureOrigins:config.local ? [config.local.callback_destination.origin]:[],controlBase:site.callback_base,profile:config.profile,localDestination:config.local?.callback_destination});
    }
    if(!exactKeys(config.firestore,['project','database']) || !/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/.test(config.firestore.project) || !/^(?:\(default\)|[a-z][a-z0-9-]{0,61}[a-z0-9])$/.test(config.firestore.database) || !/^[1-9][0-9]{5,19}$/.test(config.secret_project))throw new Error();
    if(!exactKeys(config.job,['name','container','image','generation','runner_service_account']) || config.job.image.length>512 || !/^projects\/(?:[1-9][0-9]{5,19}|[a-z][a-z0-9-]{4,28}[a-z0-9])\/locations\/[a-z]+-[a-z]+[1-9][0-9]?\/jobs\/[a-z][a-z0-9-]{0,62}$/.test(config.job.name) || !/^[a-z][a-z0-9-]{0,62}$/.test(config.job.container) || !/^[a-z0-9.-]+\/[A-Za-z0-9_./-]+@sha256:[a-f0-9]{64}$/.test(config.job.image) || !/^[1-9][0-9]{0,18}$/.test(config.job.generation) || !/^[a-z][a-z0-9-]{4,28}[a-z0-9]@[a-z][a-z0-9-]{4,28}[a-z0-9]\.iam\.gserviceaccount\.com$/.test(config.job.runner_service_account))throw new Error();
    if(!exactKeys(config.scheduler,['audience','subject','email']) || !/^https:\/\//.test(config.scheduler.audience) || !/^[1-9][0-9]{5,24}$/.test(config.scheduler.subject) || !/^[a-z][a-z0-9-]{4,28}[a-z0-9]@[a-z][a-z0-9-]{4,28}[a-z0-9]\.iam\.gserviceaccount\.com$/.test(config.scheduler.email))throw new Error();
    const audience=new URL(config.scheduler.audience);if(audience.protocol!=='https:' || audience.username || audience.password || audience.search || audience.hash || audience.pathname!=='/internal/reconcile')throw new Error();
    if(config.local){const local=config.local;if(!exactKeys(local.firestore,['host','port']) || !/^(?:127\.0\.0\.1|[a-z][a-z0-9-]{0,62})$/.test(local.firestore.host) || !Number.isInteger(local.firestore.port) || local.firestore.port<1 || local.firestore.port>65535 || ![local.shared_directory,local.run_directory,local.queue_directory,local.worker_key_file].every(value=>typeof value==='string' && isAbsolute(value)))throw new Error();}
    if(['FIRESTORE_EMULATOR_HOST','SECRET_MANAGER_EMULATOR_HOST','GOOGLE_APPLICATION_CREDENTIALS','GOOGLE_SDK_NODE_LOGGING','NODE_DEBUG','GRPC_TRACE','GRPC_VERBOSITY','GOOGLE_CLOUD_UNIVERSE_DOMAIN','GOOGLE_API_USE_MTLS_ENDPOINT','GOOGLE_API_USE_CLIENT_CERTIFICATE','FIRESTORE_PREFER_REST'].some(key=>environment[key]))throw new Error();
    if(config.profile==='cloud') {
      if(config.local)throw new Error();
    } else if(!config.local || !exactKeys(config.local,['callback_destination','firestore','shared_directory','run_directory','queue_directory','worker_key_file']) || environment.K_SERVICE || environment.CLOUD_RUN_JOB || environment.CLOUD_RUN_EXECUTION)throw new Error();
    return structuredClone(config);
  } catch { throw new Error('Dispatcherの固定登録設定を確認してください。'); }
}
export async function loadConfig(environment:NodeJS.ProcessEnv=process.env):Promise<DispatcherConfig>{try{return checkedConfig(JSON.parse((await readPrivateFile(environment.ODVR_DISPATCHER_CONFIG ?? '/etc/odvr/dispatcher.json',65536)).toString('utf8')),environment);}catch{throw new Error('Dispatcherの固定登録設定を確認してください。');}}
