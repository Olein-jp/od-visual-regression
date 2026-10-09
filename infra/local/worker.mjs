import http from 'node:http';
import { setTimeout as wait } from 'node:timers/promises';
import { readPrivateFile } from '@odvr/runner/jobs/job-config';
if(process.env.K_SERVICE || process.env.CLOUD_RUN_JOB || process.env.CLOUD_RUN_EXECUTION)throw new Error('local workerの実行条件を確認してください。');
const controller=new AbortController();process.once('SIGTERM',()=>controller.abort());process.once('SIGINT',()=>controller.abort());
while(!controller.signal.aborted){const bytes=await readPrivateFile('/run/odvr/secrets/worker-key',128,true);try{if(!/^[A-Za-z0-9_-]{43}$/.test(bytes.toString('ascii')))throw new Error();await new Promise(resolve=>{const request=http.request({hostname:'dispatcher',port:8080,path:'/internal/reconcile',method:'POST',headers:{Authorization:'Bearer '+bytes.toString('ascii'),'Content-Length':'0'},timeout:55000},response=>{response.resume();response.once('end',resolve);});request.on('error',resolve);request.on('timeout',()=>request.destroy());request.end();});}finally{bytes.fill(0);}await wait(60000,undefined,{signal:controller.signal}).catch(()=>{});}
