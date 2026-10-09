import { loadConfig } from './config.js';
import { FirestoreStore } from './firestore-store.js';
import { AcceptanceLedger } from './ledger.js';
import { GoogleSecretStore } from './google-secrets.js';
import { GoogleJobLauncher } from './google-jobs.js';
import { LocalSecretStore } from './local-secrets.js';
import { LocalJobQueue } from './local-jobs.js';
import { HmacVerifier } from './hmac.js';
import { DispatchEngine } from './engine.js';
import { WorkerAuth } from './worker-auth.js';
import { probeCallback } from './callback.js';
import { createDispatcherServer } from './server.js';
async function main():Promise<void>{
 if(process.argv.length!==2)throw new Error();const config=await loadConfig();const port=Number(process.env.PORT ?? '8080');if(!Number.isInteger(port) || port<1 || port>65535)throw new Error();
 const store=new FirestoreStore(config.firestore.project,config.firestore.database,config.local?.firestore);
 const secrets=config.profile==='cloud' ? new GoogleSecretStore(config):new LocalSecretStore(config);
 const jobs=config.profile==='cloud' ? new GoogleJobLauncher(config):new LocalJobQueue(config);
 const engine=new DispatchEngine({ledger:new AcceptanceLedger(store),secrets,jobs,config});const worker=new WorkerAuth(config);
 const server=createDispatcherServer({engine,verifier:new HmacVerifier(config.sites,resource=>secrets.shared(resource)),workerAuth:headers=>worker.authenticate(headers),probe:site=>probeCallback(config,site),log:entry=>process.stdout.write(JSON.stringify(entry)+'\n')});
 let stopping=false;const stop=()=>{if(stopping)return;stopping=true;server.close(()=>{Promise.allSettled([store.close(),secrets.close(),jobs.close()]).then(()=>{process.exitCode=0;});});server.closeIdleConnections();};process.once('SIGTERM',stop);process.once('SIGINT',stop);await new Promise<void>((resolve,reject)=>{server.once('error',reject);server.listen(port,'0.0.0.0',resolve);});
}
main().catch(()=>{process.stderr.write('Dispatcherの起動条件を確認してください。\n');process.exitCode=1;});
