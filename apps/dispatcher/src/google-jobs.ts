import { v2,protos } from '@google-cloud/run';
import type { DispatcherConfig } from './config.js';
import type { Acceptance,ExecutionInfo,JobLauncher } from './types.js';
import { DispatchError,LaunchRejected } from './types.js';
import { timestampMillis } from './google-secrets.js';
const rpc={timeout:5000,retry:null};
const rejected=(error:unknown)=>[3,7,16].includes(Number((error as {code?:number})?.code));
export function jobEnvironment(config:DispatcherConfig,record:Acceptance):Record<string,string>{if(!record.secret_version || !record.attempt_id || !config.sites[record.site_id])throw new DispatchError('odvr_invalid_ledger',503,false);return {ODVR_SITE_ID:record.site_id,ODVR_RUN_UUID:record.run_uuid,ODVR_CALLBACK_BASE:config.sites[record.site_id].callback_base,ODVR_RUN_SECRET_VERSION:record.secret_version,ODVR_TOKEN_EXPIRES_AT:new Date(record.expires_at).toISOString(),ODVR_ATTEMPT_ID:record.attempt_id};}
export class GoogleJobLauncher implements JobLauncher {
  readonly #jobs:v2.JobsClient;
  readonly #executions:v2.ExecutionsClient;
  #etag?:string;
  constructor(readonly config:DispatcherConfig){this.#jobs=new v2.JobsClient({projectId:config.job.name.split('/')[1],apiEndpoint:'run.googleapis.com',universeDomain:'googleapis.com'});this.#executions=new v2.ExecutionsClient({projectId:config.job.name.split('/')[1],apiEndpoint:'run.googleapis.com',universeDomain:'googleapis.com'});}
  async verify(record?:Acceptance):Promise<boolean>{
    const expected=this.config.job;
    if(record && (record.image!==expected.image || record.job_generation!==expected.generation))return false;
    const [job]=await this.#jobs.getJob({name:expected.name},rpc);const task=job.template?.template;const containers=task?.containers ?? [];const container=containers[0];
    const valid=job.name===expected.name && job.generation?.toString()===expected.generation && !job.reconciling && job.template?.taskCount===1 && job.template.parallelism===1 && task?.serviceAccount===expected.runner_service_account && task.maxRetries===1 && Number(task.timeout?.seconds)===1800 && task.executionEnvironment===2 && containers.length===1 && container?.name===expected.container && container.image===expected.image && (container.env ?? []).every(item=>!item.valueSource && ((item.name==='ODVR_JOB_CONFIG' && item.value==='/etc/odvr/runner.json') || (item.name==='NODE_ENV' && item.value==='production'))) && !container.args?.length && JSON.stringify(container.command ?? [])===JSON.stringify(['node','apps/runner/dist/job.js']) && container.resources?.limits?.cpu==='2' && container.resources?.limits?.memory==='2Gi' && typeof job.etag==='string' && !!job.etag;
    if(valid)this.#etag=job.etag!;return valid;
  }
  environment(record:Acceptance):Record<string,string>{return jobEnvironment(this.config,record);}
  async launch(record:Acceptance):Promise<string>{
    if(!this.#etag)throw new LaunchRejected();
    try{const [operation]=await this.#jobs.runJob({name:this.config.job.name,etag:this.#etag,overrides:{containerOverrides:[{name:this.config.job.container,env:Object.entries(this.environment(record)).map(([name,value])=>({name,value}))}]}},rpc);const name=operation.name ?? '';this.#operationName(name);return name;}
    catch(error){if(rejected(error))throw new LaunchRejected();throw error;}
  }
  #operationName(name:string):void{const prefix=this.config.job.name.split('/jobs/')[0]+'/operations/';if(!name.startsWith(prefix) || !/^[A-Za-z0-9_-]{1,200}$/.test(name.slice(prefix.length)))throw new DispatchError('odvr_operation_mismatch',503,false);}
  #executionName(name:string):void{const prefix=this.config.job.name+'/executions/';if(!name.startsWith(prefix) || !/^[a-z][a-z0-9-]{0,199}$/.test(name.slice(prefix.length)))throw new DispatchError('odvr_execution_mismatch',503,false);}
  #info(value:protos.google.cloud.run.v2.IExecution):ExecutionInfo{
    const name=value.name ?? '';this.#executionName(name);const task=value.template;const containers=task?.containers ?? [];const container=containers[0];
    if(value.job!==this.config.job.name || value.taskCount!==1 || value.parallelism!==1 || containers.length!==1 || task?.serviceAccount!==this.config.job.runner_service_account || container?.name!==this.config.job.container || typeof container.image!=='string')throw new DispatchError('odvr_execution_mismatch',503,false);
    const environment:Record<string,string>={};for(const item of container.env ?? []){if(!item.name || item.valueSource || typeof item.value!=='string' || Object.hasOwn(environment,item.name))throw new DispatchError('odvr_execution_mismatch',503,false);environment[item.name]=item.value;}
    const doneCount=(value.succeededCount ?? 0)+(value.failedCount ?? 0)+(value.cancelledCount ?? 0);
    const terminal=!!value.completionTime && !value.reconciling && (value.runningCount ?? 0)===0 && doneCount===1;
    return {name,created_at:timestampMillis(value.createTime),terminal,image:container.image,environment};
  }
  async operation(name:string):Promise<{execution:ExecutionInfo|null;failed:boolean}>{
    this.#operationName(name);const [operation]=await this.#jobs.getOperation(new protos.google.longrunning.GetOperationRequest({name}),rpc);if(operation.name!==name)throw new DispatchError('odvr_operation_mismatch',503,false);
    for(const any of [operation.metadata,operation.response]){if(any?.type_url==='type.googleapis.com/google.cloud.run.v2.Execution' && any.value){const data=typeof any.value==='string' ? Buffer.from(any.value,'base64'):any.value;if(data.byteLength>1024*1024)throw new DispatchError('odvr_execution_mismatch',503,false);return {execution:this.#info(protos.google.cloud.run.v2.Execution.decode(data)),failed:false};}}
    return {execution:null,failed:!!operation.done && rejected(operation.error)};
  }
  async execution(name:string):Promise<ExecutionInfo>{this.#executionName(name);const [value]=await this.#executions.getExecution({name},rpc);if(value.name!==name)throw new DispatchError('odvr_execution_mismatch',503,false);return this.#info(value);}
  async candidates(record:Acceptance):Promise<ExecutionInfo[]>{
    const deadline=Date.now()+10000;const result:ExecutionInfo[]=[];const tokens=new Set<string>();let pageToken:string|undefined;
    for(let page=0;page<100;page++){if(Date.now()>=deadline)throw new DispatchError('odvr_execution_list_unavailable',503,true);
      const [items,,response]=await this.#executions.listExecutions({parent:this.config.job.name,pageSize:100,pageToken},{...rpc,autoPaginate:false});
      for(const value of items){if(Date.now()>=deadline)throw new DispatchError('odvr_execution_list_unavailable',503,true);const created=timestampMillis(value.createTime);if(created<record.created_at-300000 || created>record.created_at+900000)continue;const name=value.name ?? '';this.#executionName(name);result.push(await this.execution(name));}
      pageToken=response.nextPageToken || undefined;if(!pageToken)return result;if(tokens.has(pageToken))throw new DispatchError('odvr_execution_list_unavailable',503,true);tokens.add(pageToken);
    }
    // 全pageを確認できない場合は候補を確定せず、workerへ渡す。
    throw new DispatchError('odvr_execution_list_unavailable',503,true);
  }
  async close():Promise<void>{await Promise.all([this.#jobs.close(),this.#executions.close()]);}
}
