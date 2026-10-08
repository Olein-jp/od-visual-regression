import type { DispatchRequest } from '@odvr/shared';
export type AcceptanceStatus='provisioning'|'accepted'|'launching'|'launch_unknown'|'started'|'failed';
export type SecretStep='new'|'creating'|'created'|'adding'|'versioned'|'ready';
export interface Acceptance {
  storage_version:1;
  site_id:string;
  run_uuid:string;
  digest:string;
  created_at:number;
  next_action_at:number;
  signed_at:number;
  expires_at:number;
  status:AcceptanceStatus;
  secret_step:SecretStep;
  secret_version:string|null;
  secret_deleted:boolean;
  attempt_id:string|null;
  operation:string|null;
  execution:string|null;
  execution_terminal_at:number|null;
  active:boolean;
  job_generation:string;
  image:string;
  error_code:string|null;
  lease:{owner:string|null;generation:number;until:number};
}
export interface SiteCounter { storage_version:1; recent:number[]; active:string[] }
export interface Mutation<R> { record:Acceptance|null; counter:SiteCounter; value:R }
export interface LedgerStore {
  transaction<R>(site:string,uuid:string,change:(record:Acceptance|null,counter:SiteCounter)=>Mutation<R>):Promise<R>;
  get(site:string,uuid:string):Promise<Acceptance|null>;
  scan(cursor:string|null,limit:number):Promise<{records:Acceptance[];cursor:string|null}>;
  health():Promise<void>;
  secretCursor():Promise<string|null>;
  saveSecretCursor(cursor:string|null):Promise<void>;
  close():Promise<void>;
}
export interface SecretMetadata { name:string;expires_at:number;labels:Record<string,string> }
export interface SecretStore {
  shared(resource:string):Promise<Buffer>;
  create(record:Acceptance):Promise<void>;
  inspect(record:Acceptance):Promise<SecretMetadata|null>;
  versions(record:Acceptance):Promise<string[]>;
  add(record:Acceptance,token:string):Promise<string>;
  grant(record:Acceptance):Promise<void>;
  remove(record:Acceptance):Promise<void>;
  orphans(cursor:string|null,limit:number):Promise<{secrets:SecretMetadata[];cursor:string|null}>;
  removeOrphan(secret:SecretMetadata):Promise<void>;
  close():Promise<void>;
}
export interface ExecutionInfo {
  name:string;
  created_at:number;
  terminal:boolean;
  image:string;
  environment:Record<string,string>;
}
export interface JobLauncher {
  verify(record?:Acceptance):Promise<boolean>;
  launch(record:Acceptance):Promise<string>;
  operation(name:string):Promise<{execution:ExecutionInfo|null;failed:boolean}>;
  execution(name:string):Promise<ExecutionInfo>;
  candidates(record:Acceptance):Promise<ExecutionInfo[]>;
  close():Promise<void>;
}
export class DispatchError extends Error {
  constructor(readonly code:string,readonly status:number,readonly retryable=false){super('受付または起動の条件を確認してください。');}
}
export class LaunchRejected extends Error { constructor(){super('Jobの起動を受け付けられませんでした。');} }
export type ValidDispatch=DispatchRequest;
