/** 実Image・既存wp-envを使用する最小結合検証。Cloudへは接続しない。 */
import { spawn } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { setTimeout as wait } from 'node:timers/promises';
const metadata=JSON.parse(await readFile('.odvr-local/images.json','utf8'));const environment={...process.env,ODVR_RUNNER_IMAGE:metadata.images.runner,ODVR_DISPATCHER_IMAGE:metadata.images.dispatcher,ODVR_LOCAL_INPUT:new URL('../../.odvr-local/input',import.meta.url).pathname};
function command(args){return new Promise((resolve,reject)=>{let output='';const child=spawn(args[0],args.slice(1),{env:environment,stdio:['ignore','pipe','inherit']});child.stdout.on('data',chunk=>{output+=chunk;});child.once('error',reject);child.once('exit',code=>code===0 ? resolve(output.trim()):reject(new Error('local結合検証の操作に失敗しました。')));});}
const local=phase=>command([process.execPath,'infra/scripts/local.mjs',phase]);const compose=(...args)=>command(['docker','compose','--profile','local',...args]);
async function record(uuid){return JSON.parse(await compose('exec','-T','dispatcher','node','--input-type=module','-e',`import {FirestoreStore} from './apps/dispatcher/dist/firestore-store.js';const store=new FirestoreStore('odvr-local','(default)',{host:'firestore',port:8085});try{const r=await store.get('local-fixture',${JSON.stringify(uuid)});if(!r)throw new Error('受付記録がありません');console.log(JSON.stringify({digest:r.digest,attempt_id:r.attempt_id,execution:r.execution,status:r.status,secret_deleted:r.secret_deleted,active:r.active}));}finally{await store.close();}`));}
try{await readFile('.odvr-local/state.json');throw new Error('前回のlocal環境を停止してから検証してください。');}catch(error){if(error.code!=='ENOENT')throw error;}
const wordpress=(await command(['docker','ps','--format','{{.Names}}'])).split('\n').filter(name=>/^wp-env-[a-z0-9-]+-wordpress-1$/.test(name) && !name.endsWith('-tests-wordpress-1') && name.includes('od-visual-regression'));assert.equal(wordpress.length,1);
const configuration=()=>command(['docker','exec',wordpress[0],'php','-r',"$p='/var/www/html/wp-config.php'; echo json_encode(array('hash'=>hash_file('sha256',$p),'uid'=>fileowner($p),'gid'=>filegroup($p),'mode'=>fileperms($p)&0777));"]);
const original=await configuration();
let started=false;
try{
 started=true;await local('start');await local('check');
 const created=JSON.parse(await local('fixture'));assert.match(created.run_uuid,/^[a-f0-9-]{36}$/);
 let status;for(let attempt=0;attempt<60;attempt++){status=JSON.parse(await local('status'));if(['complete','failed'].includes(status.status))break;await wait(2000);}
 assert.equal(status.status,'complete');assert.equal(status.snapshot_status,'CAPTURED');assert.equal(status.error_code,null);
 await compose('exec','-T','launcher','node','infra/local/probe.mjs');
 let before;for(let attempt=0;attempt<40;attempt++){before=await record(created.run_uuid);if(before.secret_deleted && !before.active)break;await wait(3000);}
 assert.equal(before.secret_deleted,true);assert.equal(before.active,false);assert.match(before.execution,/\/executions\/local-/);
 await compose('restart','dispatcher','launcher');const restarted=await record(created.run_uuid);assert.deepEqual(restarted,before);
 await compose('restart','firestore');let after;for(let attempt=0;attempt<30;attempt++){try{after=await record(created.run_uuid);break;}catch{await wait(2000);}}
 assert.deepEqual(after,before);assert.equal(JSON.parse(await local('status')).status,'complete');
 console.log('実Imageで受付→撮影→Upload→Complete、秘密cleanup、Dispatcher/launcher/emulator再起動時の記録保持を確認しました。');
}finally{if(started){console.log(await local('stop'));assert.equal(await configuration(),original);await assert.rejects(readFile('.odvr-local/input/fixture-key.pem'),error=>error.code==='ENOENT');console.log('wp-configの内容・所有者・権限の復元を確認しました。');}}
