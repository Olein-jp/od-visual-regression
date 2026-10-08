import { randomUUID } from 'node:crypto';
import type { DispatchResponse } from '@odvr/shared';
import type { DispatcherConfig } from './config.js';
import type { Acceptance, ExecutionInfo, JobLauncher, SecretStore, ValidDispatch } from './types.js';
import { DispatchError,LaunchRejected } from './types.js';
import { AcceptanceLedger } from './ledger.js';
import { metadataMatches } from './google-secrets.js';
export interface EngineDependencies { ledger:AcceptanceLedger; secrets:SecretStore; jobs:JobLauncher; config:DispatcherConfig; fault?:(phase:string,record:Acceptance)=>Promise<void> }
/** 外部APIはlease取得後だけ呼ぶ。起動前記録を確定してからPOSTは一度だけ送る。 */
export class DispatchEngine {
  constructor(readonly dependencies:EngineDependencies){}
  async #fault(phase:string,record:Acceptance):Promise<void>{await this.dependencies.fault?.(phase,structuredClone(record));}
  response(record:Acceptance):{status:number;body:DispatchResponse}{
    if(record.status==='failed')throw new DispatchError('odvr_dispatch_unavailable',503,false);
    if(record.status==='provisioning')throw new DispatchError('odvr_dispatch_unavailable',503,true);
    return {status:record.status==='started' ? 200:202,body:{schema_version:1,site_id:record.site_id,run_uuid:record.run_uuid,status:record.status==='started' ? 'started':'accepted',runner_execution_id:record.execution?.split('/').at(-1) ?? null}};
  }
  async accept(input:ValidDispatch,body:Buffer,signedAt:number):Promise<{status:number;body:DispatchResponse}>{
    const {ledger,config}=this.dependencies;
    const record=await ledger.reserve(input,body,signedAt,config.job.generation,config.job.image);
    await this.#fault('reserved',record);
    const leased=await ledger.acquire(record);
    if(leased){try{await this.#process(leased,input.runner_token);}finally{await ledger.patch(leased,()=>{},true);}}
    const latest=await ledger.store.get(input.site_id,input.run_uuid);if(!latest)throw new DispatchError('odvr_dispatch_unavailable',503,true);
    await this.#fault('before_reply',latest);return this.response(latest);
  }
  async lookup(site:string,uuid:string):Promise<{status:number;body:DispatchResponse}>{const record=await this.dependencies.ledger.store.get(site,uuid);if(!record)throw new DispatchError('odvr_dispatch_not_found',404);return this.response(record);}
  async #patch(record:Acceptance,change:(current:Acceptance)=>void):Promise<Acceptance>{const current=await this.dependencies.ledger.patch(record,change);if(!current)throw new DispatchError('odvr_lease_changed',503,true);return current;}
  async #failed(record:Acceptance,code='odvr_dispatch_unavailable'):Promise<Acceptance>{return this.#patch(record,current=>{current.status='failed';current.active=false;current.error_code=code;});}
  async #cleanup(record:Acceptance):Promise<Acceptance>{if(record.secret_deleted)return record;try{await this.dependencies.secrets.remove(record);return this.#patch(record,current=>{current.secret_deleted=true;current.error_code=null;});}catch{return this.#patch(record,current=>{current.error_code='odvr_cleanup_unavailable';});}}
  async #process(initial:Acceptance,token?:string):Promise<void>{
    const {ledger,secrets,jobs,config}=this.dependencies;let record=initial;
    if(record.expires_at<=ledger.clock() && record.status!=='failed')record=await this.#failed(record,'odvr_dispatch_expired');
    if(record.status==='provisioning') {
      if(record.expires_at-ledger.clock()<60000){record=await this.#failed(record,'odvr_dispatch_expired');}
      else {
        if(['new','creating'].includes(record.secret_step)) {
          if(record.secret_step==='new')record=await this.#patch(record,current=>{current.secret_step='creating';});
          await this.#fault('before_secret_create',record);
          let metadata=await secrets.inspect(record);
          if(!metadata){await secrets.create(record);await this.#fault('after_secret_create',record);metadata=await secrets.inspect(record);}
          if(!metadata || !metadataMatches(record,metadata))throw new DispatchError('odvr_secret_ownership_conflict',503,false);
          record=await this.#patch(record,current=>{current.secret_step='created';});
        }
        if(record.secret_step==='created') {
          if(!token)record=await this.#failed(record,'odvr_secret_payload_lost');
          else {
            record=await this.#patch(record,current=>{current.secret_step='adding';});
            await this.#fault('before_secret_version',record);
            const resource=await secrets.add(record,token);token=undefined;
            await this.#fault('after_secret_version',record);
            record=await this.#patch(record,current=>{current.secret_step='versioned';current.secret_version=resource;});
          }
        }
        if(record.status==='provisioning' && record.secret_step==='adding') {
          const versions=await secrets.versions(record);
          if(versions.length!==1)record=await this.#failed(record,'odvr_secret_payload_lost');
          else record=await this.#patch(record,current=>{current.secret_step='versioned';current.secret_version=versions[0];});
        }
        if(record.status==='provisioning' && record.secret_step==='versioned') {
          await secrets.grant(record);await this.#fault('after_secret_grant',record);
          record=await this.#patch(record,current=>{current.secret_step='ready';current.status='accepted';});
          await this.#fault('accepted',record);
        }
      }
    }
    if(record.status==='accepted') {
      if(ledger.clock()>record.created_at+600000 || record.expires_at-ledger.clock()<60000)record=await this.#failed(record,'odvr_dispatch_expired');
      else if(record.job_generation!==config.job.generation || record.image!==config.job.image || !await jobs.verify(record)){await this.#patch(record,current=>{current.error_code='odvr_job_configuration_mismatch';});return;}
      else {
        record=await this.#patch(record,current=>{current.status='launching';current.attempt_id=randomUUID();current.error_code=null;});
        // ここで落ちてもworkerは再起動せず、同じattemptのExecutionを照合する。
        await this.#fault('launching',record);let operation:string;
        try{await this.#fault('before_job_run',record);operation=await jobs.launch(record);await this.#fault('after_job_run',record);}
        catch(error){if(error instanceof LaunchRejected)record=await this.#failed(record);else record=await this.#patch(record,current=>{current.status='launch_unknown';current.error_code='odvr_launch_unknown';});operation='';}
        if(operation){record=await this.#patch(record,current=>{current.operation=operation;current.status='launch_unknown';});await this.#fault('operation_saved',record);}
      }
    }
    if(['launching','launch_unknown'].includes(record.status)) {
      let selected:ExecutionInfo|null=null;let operationFailed=false;
      if(record.operation){const operation=await jobs.operation(record.operation);if(operation.execution && this.#matches(record,operation.execution))selected=operation.execution;operationFailed=operation.failed;}
      if(!selected){const matches=(await jobs.candidates(record)).filter(execution=>this.#matches(record,execution));if(matches.length===1)selected=matches[0];else if(matches.length>1){await this.#patch(record,current=>{current.status='launch_unknown';current.error_code='odvr_execution_ambiguous';});return;}}
      if(selected){record=await this.#patch(record,current=>{current.status='started';current.execution=selected!.name;current.error_code=null;});}
      else if(operationFailed)record=await this.#failed(record);
      else {await this.#patch(record,current=>{current.status='launch_unknown';current.error_code='odvr_launch_unknown';});return;}
    }
    if(record.status==='started' && record.execution_terminal_at===null) {
      if(!record.execution)throw new DispatchError('odvr_invalid_ledger',503,true);
      const execution=await jobs.execution(record.execution);
      if(!this.#matches(record,execution))throw new DispatchError('odvr_execution_mismatch',503,false);
      if(execution.terminal)record=await this.#patch(record,current=>{current.execution_terminal_at=ledger.clock();current.active=false;});
    }
    if(record.status==='failed' || (record.status==='started' && record.execution_terminal_at!==null))await this.#cleanup(record);
  }
  #matches(record:Acceptance,execution:ExecutionInfo):boolean {
    const {config}=this.dependencies;const environment=execution.environment;
    return execution.name.startsWith(config.job.name+'/executions/') && /^[a-z][a-z0-9-]{0,199}$/.test(execution.name.split('/').at(-1) ?? '') && execution.image===record.image && execution.created_at>=record.created_at-300000 && execution.created_at<=record.created_at+900000 && environment.ODVR_SITE_ID===record.site_id && environment.ODVR_RUN_UUID===record.run_uuid && environment.ODVR_CALLBACK_BASE===config.sites[record.site_id]?.callback_base && environment.ODVR_RUN_SECRET_VERSION===record.secret_version && environment.ODVR_ATTEMPT_ID===record.attempt_id && environment.ODVR_TOKEN_EXPIRES_AT===new Date(record.expires_at).toISOString();
  }
  async worker():Promise<{processed:number;deleted:number}>{
    const {ledger,secrets}=this.dependencies;let processed=0;let deleted=0;const deadline=ledger.clock()+45000;
    const {records}=await ledger.store.scan(null,100);
    for(const record of records){if(processed>=25 || ledger.clock()>=deadline)break;if(!record.active && record.secret_deleted){await ledger.forget(record);processed++;continue;}const leased=await ledger.acquire(record);if(!leased)continue;
      try{await this.#process(leased);}catch(error){const fresh=await ledger.store.get(leased.site_id,leased.run_uuid).catch(()=>null);if(error instanceof DispatchError && !error.retryable && fresh && fresh.attempt_id===null && ['provisioning','accepted'].includes(fresh.status)){await this.#failed(leased,error.code).catch(()=>{});}else await ledger.patch(leased,current=>{current.error_code=error instanceof DispatchError ? error.code:'odvr_worker_unavailable';}).catch(()=>{});}
      finally{await ledger.patch(leased,()=>{},true).catch(()=>{});}processed++;
    }
    if(ledger.clock()<deadline){const page=await secrets.orphans(await ledger.store.secretCursor(),25);for(const secret of page.secrets){if(ledger.clock()>=deadline)break;if(secret.expires_at<=ledger.clock()){try{await secrets.removeOrphan(secret);deleted++;}catch{ /* 次の周回とSecret自動期限で回収する。原文は出さない。 */ }}}await ledger.store.saveSecretCursor(page.cursor);}
    return {processed,deleted};
  }
}
