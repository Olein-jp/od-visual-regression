import { randomBytes,createHash } from 'node:crypto';
import { mkdir,readFile,writeFile,chmod,chown,copyFile,lstat,rm } from 'node:fs/promises';
import { join } from 'node:path';
import { checkedConfig } from '../../apps/dispatcher/dist/config.js';
if(process.getuid()!==0 || process.env.K_SERVICE || process.env.CLOUD_RUN_JOB || process.env.CLOUD_RUN_EXECUTION)throw new Error('local初期化の実行条件を確認してください。');
const input='/input';const output='/etc/odvr';const secrets='/run/odvr/secrets';
await rm(secrets+'/.initialized',{force:true});
const config=checkedConfig(JSON.parse(await readFile(input+'/dispatcher.json','utf8')));if(config.profile!=='local')throw new Error('local設定を確認してください。');
for(const directory of [output,secrets,secrets+'/runs',secrets+'/shared','/run/odvr/queue','/data']){await mkdir(directory,{recursive:true,mode:0o700});await chmod(directory,directory===output ? 0o755:0o700);await chown(directory,1000,1000);}
for(const file of ['dispatcher.json','runner.json','launcher.json','relay.json','fixture-ca.pem']){const source=join(input,file);if(!(await lstat(source)).isFile())throw new Error('local入力を確認してください。');await copyFile(source,join(output,file));await chmod(join(output,file),0o644);await chown(join(output,file),1000,1000);}
await copyFile(input+'/fixture-key.pem',secrets+'/relay-key');await chmod(secrets+'/relay-key',0o600);await chown(secrets+'/relay-key',1000,1000);
const resource=config.sites[Object.keys(config.sites)[0]].shared_versions[0];const sharedFile=secrets+'/shared/'+createHash('sha256').update(resource).digest('hex');
for(const [path,value] of [[sharedFile,'fixture-only-'+randomBytes(32).toString('base64url')],[secrets+'/worker-key',randomBytes(32).toString('base64url')]]){try{await writeFile(path,value,{flag:'wx',mode:0o600});await chown(path,1000,1000);}catch(error){if(error.code!=='EEXIST')throw error;}}

await writeFile(secrets+'/.initialized','ready',{mode:0o644});process.setgid(1000);process.setuid(1000);const hold=setInterval(()=>{},60000);process.once('SIGTERM',()=>clearInterval(hold));process.once('SIGINT',()=>clearInterval(hold));
