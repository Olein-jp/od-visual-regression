import { spawn } from 'node:child_process';
import { readdir,readFile,writeFile,rename,rm,open } from 'node:fs/promises';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { setTimeout as wait } from 'node:timers/promises';
import { readPrivateFile } from '@odvr/runner/jobs/job-config';
import { checkedLocalEntry } from '@odvr/runner/jobs/local-queue';
import { localRegistration } from './check.mjs';
const queue='/run/odvr/queue';const launcherConfig='/etc/odvr/launcher.json';
export async function updateQueue(path,entry){const temporary=path+'.'+randomUUID()+'.tmp';try{const file=await open(temporary,'wx',0o600);try{await file.writeFile(JSON.stringify(entry));await file.sync();}finally{await file.close();}await rename(temporary,path);}finally{await rm(temporary,{force:true});}}
export async function runTask(entry,signal,spawnTask=spawn,clock=Date.now){
 const expiry=Date.parse(entry.execution.environment.ODVR_TOKEN_EXPIRES_AT);const deadline=Math.min(entry.task_started_at+1800000,expiry);if(deadline<=clock())return 1;
 const values=entry.execution.environment;
 // cloud数値versionはqueue内の照合用だけ。local Runnerは固定site/UUIDファイルを読む。
 const environment={PATH:process.env.PATH,HOME:'/tmp',NODE_ENV:'production',PLAYWRIGHT_BROWSERS_PATH:'/ms-playwright',NODE_EXTRA_CA_CERTS:'/etc/odvr/fixture-ca.pem',ODVR_JOB_CONFIG:'/etc/odvr/runner.json',ODVR_SITE_ID:values.ODVR_SITE_ID,ODVR_RUN_UUID:values.ODVR_RUN_UUID,ODVR_CALLBACK_BASE:values.ODVR_CALLBACK_BASE,ODVR_TOKEN_EXPIRES_AT:values.ODVR_TOKEN_EXPIRES_AT,ODVR_LOCAL_EXECUTION_ID:entry.execution.name.split('/').at(-1)};
 return new Promise(resolve=>{let done=false;let grace;let stopping=false;const child=spawnTask(process.execPath,['apps/runner/dist/job.js'],{cwd:'/app',env:environment,detached:true,stdio:['ignore','inherit','inherit']});const kill=signal=>{try{if(Number.isInteger(child.pid))process.kill(-child.pid,signal);else child.kill(signal);}catch{/* 終了済みprocess groupは再利用しない。 */}};const stop=()=>{if(done || stopping)return;stopping=true;grace=setTimeout(()=>kill('SIGKILL'),10000);kill('SIGTERM');};const timer=setTimeout(stop,Math.max(1,deadline-clock()));signal.addEventListener('abort',stop,{once:true});if(signal.aborted)stop();const finish=code=>{if(done)return;if(stopping || code!==0)kill('SIGKILL');done=true;clearTimeout(timer);clearTimeout(grace);signal.removeEventListener('abort',stop);resolve(Number.isInteger(code) && code===0 ? 0:1);};child.once('error',()=>finish(1));child.once('exit',finish);});
}
export async function processEntry(path,registration,signal,execute=runTask,clock=Date.now){
 let entry=checkedLocalEntry(JSON.parse((await readPrivateFile(path,16384,true)).toString('utf8')),registration,path.split('/').at(-1),true);if(entry.state==='terminal')return;
 // runningは前プロセスの中断を意味する。開始前に試行数を確定し、3回目を作らない。
 for(;entry.attempts<2 && !signal.aborted;){if(Date.parse(entry.execution.environment.ODVR_TOKEN_EXPIRES_AT)<=clock())break;entry.attempts++;entry.state='running';entry.task_started_at=clock();await updateQueue(path,entry);const code=await execute(entry,signal);if(code===0)break;if(signal.aborted)return;await wait(1000);}
 if(signal.aborted)return;entry.state='terminal';entry.execution.terminal=true;entry.task_started_at ??=clock();if(entry.attempts===0)entry.attempts=1;await updateQueue(path,entry);
}
async function main(){const config=JSON.parse((await readPrivateFile(launcherConfig,16384)).toString('utf8'));const runner=await localRegistration('/etc/odvr/runner.json');if(Object.keys(config).sort().join()!=='job,secret_project,sites' || JSON.stringify(config.sites)!==JSON.stringify(runner.sites))throw new Error();const controller=new AbortController();process.once('SIGTERM',()=>controller.abort());process.once('SIGINT',()=>controller.abort());while(!controller.signal.aborted){const files=(await readdir(queue)).filter(name=>/^[a-f0-9]{64}\.json$/.test(name)).sort();if(files.length>10000)throw new Error();for(const file of files){if(controller.signal.aborted)break;await processEntry(join(queue,file),config,controller.signal);}await wait(1000,undefined,{signal:controller.signal}).catch(()=>{});}}
if(import.meta.url===new URL(process.argv[1],'file:').href)main().catch(()=>{process.stderr.write('local launcherの設定または実行記録を確認してください。\n');process.exitCode=1;});
