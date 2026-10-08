import { join } from 'node:path';
import { FileStore } from '../../dist/file-store.js';
import { AcceptanceLedger } from '../../dist/ledger.js';
import { LocalSecretStore } from '../../dist/local-secrets.js';
import { LocalJobQueue } from '../../dist/local-jobs.js';
import { DispatchEngine } from '../../dist/engine.js';
let text='';for await(const chunk of process.stdin)text+=chunk;const fixture=JSON.parse(text);const body=Buffer.from(fixture.body,'base64');const input=JSON.parse(body);const clock=()=>fixture.now;const engine=new DispatchEngine({config:fixture.config,ledger:new AcceptanceLedger(new FileStore(join(fixture.root,'ledger'),clock),clock),secrets:new LocalSecretStore(fixture.config,clock),jobs:new LocalJobQueue(fixture.config,clock),fault:async phase=>{if(phase===fixture.phase)process.exit(25);}});await engine.accept(input,body,fixture.now);process.exit(1);
