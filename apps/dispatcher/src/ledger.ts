import { createHash, randomUUID } from 'node:crypto';
import type { Acceptance, LedgerStore, SiteCounter, ValidDispatch } from './types.js';
import { DispatchError } from './types.js';
export const ledgerKey=(site:string,uuid:string)=>createHash('sha256').update(site+'\n'+uuid).digest('hex');
export const emptyCounter=():SiteCounter=>({storage_version:1,recent:[],active:[]});
const statuses=['provisioning','accepted','launching','launch_unknown','started','failed'];
const steps=['new','creating','created','adding','versioned','ready'];
/** 保存形式も許可リストで検査し、payloadの保存・任意Google resourceの混入を防ぐ。 */
export function checkedRecord(value:unknown):Acceptance {
  if(!value || typeof value!=='object') throw new DispatchError('odvr_invalid_ledger',503,true);
  const record=value as Acceptance;
  const keys=['storage_version','site_id','run_uuid','digest','created_at','next_action_at','signed_at','expires_at','status','secret_step','secret_version','secret_deleted','attempt_id','operation','execution','execution_terminal_at','active','job_generation','image','error_code','lease'];
  if(Object.keys(record).length!==keys.length || Object.keys(record).some(key=>!keys.includes(key)) || record.storage_version!==1 || !/^[A-Za-z0-9_-]{1,100}$/.test(record.site_id) || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(record.run_uuid) || !/^[a-f0-9]{64}$/.test(record.digest) || !statuses.includes(record.status) || !steps.includes(record.secret_step) || typeof record.active!=='boolean' || typeof record.secret_deleted!=='boolean' || ![record.created_at,record.next_action_at,record.signed_at,record.expires_at,record.lease?.generation,record.lease?.until].every(Number.isSafeInteger) || record.expires_at!==record.signed_at+5400000 || !record.lease || Object.keys(record.lease).sort().join()!=='generation,owner,until') throw new DispatchError('odvr_invalid_ledger',503,true);
  const nullable=(value:unknown,pattern:RegExp)=>value===null || (typeof value==='string' && pattern.test(value));
  if(record.lease.generation<0 || record.lease.until<0 || !nullable(record.lease.owner,/^[a-f0-9-]{36}$/) || !nullable(record.attempt_id,/^[a-f0-9-]{36}$/) || !nullable(record.secret_version,/^projects\/[1-9][0-9]{5,19}\/secrets\/odvr-run-[a-f0-9]{24}-[a-f0-9-]{36}\/versions\/[1-9][0-9]{0,9}$/) || !nullable(record.operation,/^projects\/[a-z0-9-]{6,30}\/locations\/[a-z0-9-]{1,63}\/operations\/[A-Za-z0-9_-]{1,200}$/) || !nullable(record.execution,/^projects\/[a-z0-9-]{6,30}\/locations\/[a-z0-9-]{1,63}\/jobs\/[a-z0-9-]{1,63}\/executions\/[a-z][a-z0-9-]{0,199}$/) || !nullable(record.error_code,/^odvr_[a-z0-9_]{1,100}$/) || (record.execution_terminal_at!==null && !Number.isSafeInteger(record.execution_terminal_at)) || typeof record.image!=='string' || record.image.length>512 || !/^[1-9][0-9]{0,18}$/.test(record.job_generation))throw new DispatchError('odvr_invalid_ledger',503,true);
  return structuredClone(record);
}
export function checkedCounter(value:unknown):SiteCounter {
  const counter=value as SiteCounter;
  if(!counter || Object.keys(counter).sort().join()!=='active,recent,storage_version' || counter.storage_version!==1 || !Array.isArray(counter.recent) || counter.recent.length>10 || !counter.recent.every(Number.isSafeInteger) || !Array.isArray(counter.active) || counter.active.length>2 || !counter.active.every(item=>typeof item==='string') || new Set(counter.active).size!==counter.active.length) throw new DispatchError('odvr_invalid_ledger',503,true);
  return structuredClone(counter);
}
export class AcceptanceLedger {
  constructor(readonly store:LedgerStore,readonly clock=Date.now){}
  async reserve(input:ValidDispatch,body:Buffer,signedAt:number,jobGeneration:string,image:string):Promise<Acceptance> {
    const digest=createHash('sha256').update(body).digest('hex');
    return this.store.transaction(input.site_id,input.run_uuid,(old,counter)=>{
      if(old){if(old.digest!==digest)throw new DispatchError('odvr_dispatch_conflict',409);return {record:old,counter,value:old};}
      const now=this.clock();counter.recent=counter.recent.filter(time=>time>now-60000);
      if(counter.recent.length>=10 || counter.active.length>=2)throw new DispatchError('odvr_dispatch_rate_limit',429,true);
      if(signedAt+5400000-now<60000)throw new DispatchError('odvr_dispatch_unavailable',503,false);
      const record:Acceptance={storage_version:1,site_id:input.site_id,run_uuid:input.run_uuid,digest,created_at:now,next_action_at:now,signed_at:signedAt,expires_at:signedAt+5400000,status:'provisioning',secret_step:'new',secret_version:null,secret_deleted:false,attempt_id:null,operation:null,execution:null,execution_terminal_at:null,active:true,job_generation:jobGeneration,image,error_code:null,lease:{owner:null,generation:0,until:0}};
      counter.recent.push(now);counter.active.push(input.run_uuid);return {record,counter,value:record};
    });
  }
  async acquire(record:Acceptance):Promise<Acceptance|null> {
    return this.store.transaction(record.site_id,record.run_uuid,(current,counter)=>{
      if(!current || current.lease.until>this.clock())return {record:current,counter,value:null};
      current.lease={owner:randomUUID(),generation:current.lease.generation+1,until:this.clock()+60000};current.next_action_at=current.lease.until;
      return {record:current,counter,value:current};
    });
  }
  async patch(leased:Acceptance,change:(record:Acceptance)=>void,release=false):Promise<Acceptance|null> {
    return this.store.transaction(leased.site_id,leased.run_uuid,(record,counter)=>{
      if(!record || record.lease.owner!==leased.lease.owner || record.lease.generation!==leased.lease.generation || record.lease.until<=this.clock())return {record,counter,value:null};
      change(record);
      if(!record.active)counter.active=counter.active.filter(uuid=>uuid!==record.run_uuid);
      if(release){record.lease={...record.lease,owner:null,until:0};record.next_action_at=!record.active && record.secret_deleted ? record.created_at+2592000000:this.clock()+60000;}
      return {record,counter,value:record};
    });
  }
  async forget(record:Acceptance):Promise<void> {
    await this.store.transaction(record.site_id,record.run_uuid,(current,counter)=>({record:current && !current.active && current.secret_deleted && current.created_at+2592000000<this.clock() ? null:current,counter,value:undefined}));
  }
}
