import { mkdtemp,mkdir,rm,readdir,readFile,writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { FileStore } from '../../dist/file-store.js';
import { AcceptanceLedger } from '../../dist/ledger.js';
import { LocalSecretStore } from '../../dist/local-secrets.js';
import { LocalJobQueue } from '../../dist/local-jobs.js';
import { DispatchEngine } from '../../dist/engine.js';
export const token='dummy-run-token'.padEnd(43,'A');
export const shared=Buffer.from('dummy-shared-secret-32-bytes-long');
export async function context(t){
 const root=await mkdtemp(join(tmpdir(),'odvr-dispatch-'));if(t)t.after(()=>rm(root,{recursive:true,force:true}));for(const name of ['runs','shared','queue','ledger'])await mkdir(join(root,name),{mode:0o700});
 const config={schema_version:1,profile:'local',sites:{'fixture-site':{callback_base:'https://wordpress.test/wp-json/odvr/v1/runner',shared_versions:['projects/111111111111/secrets/fixture-shared/versions/1'],rotation_until:null}},firestore:{project:'fixture-project',database:'(default)'},secret_project:'222222222222',job:{name:'projects/fixture-project/locations/asia-northeast1/jobs/runner',container:'runner',image:'asia-northeast1-docker.pkg.dev/fixture-project/images/runner@sha256:'+'1'.repeat(64),generation:'1',runner_service_account:'runner-sa@fixture-project.iam.gserviceaccount.com'},scheduler:{audience:'https://dispatcher.test/internal/reconcile',subject:'123456789012345678',email:'worker-sa@fixture-project.iam.gserviceaccount.com'},local:{callback_destination:{origin:'https://wordpress.test',address:'192.168.1.2',port:443},firestore:{host:'127.0.0.1',port:8085},run_directory:join(root,'runs'),shared_directory:join(root,'shared'),queue_directory:join(root,'queue'),worker_key_file:join(root,'worker-key')}};
 let now=Math.floor(Date.now()/1000)*1000;const clock=()=>now;const store=new FileStore(join(root,'ledger'),clock);const ledger=new AcceptanceLedger(store,clock);const secrets=new LocalSecretStore(config,clock);const jobs=new LocalJobQueue(config,clock);const launch=jobs.launch.bind(jobs);jobs.launch=async record=>{const operation=await launch(record);const file=join(config.local.queue_directory,(await readdir(config.local.queue_directory)).find(name=>name.endsWith('.json')));const entry=JSON.parse(await readFile(file,'utf8'));entry.state='running';entry.attempts=1;await writeFile(file,JSON.stringify(entry));return operation;};const engine=new DispatchEngine({config,ledger,secrets,jobs});const input={schema_version:1,site_id:'fixture-site',run_uuid:randomUUID(),callback_base:config.sites['fixture-site'].callback_base,runner_token:token};const body=Buffer.from(JSON.stringify(input));return {root,config,store,ledger,secrets,jobs,engine,input,body,clock,advance:ms=>{now+=ms;},record:()=>store.get(input.site_id,input.run_uuid),restart:()=>new DispatchEngine({config,ledger:new AcceptanceLedger(new FileStore(join(root,'ledger'),clock),clock),secrets:new LocalSecretStore(config,clock),jobs:new LocalJobQueue(config,clock)})};
}
