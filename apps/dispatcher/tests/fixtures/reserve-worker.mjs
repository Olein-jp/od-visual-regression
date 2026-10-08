import { FileStore } from '../../dist/file-store.js';
import { AcceptanceLedger } from '../../dist/ledger.js';
let raw='';for await(const chunk of process.stdin)raw+=chunk;
try{const {body,signed_at}=JSON.parse(raw);const buffer=Buffer.from(body,'base64');const input=JSON.parse(buffer);const store=new FileStore(process.env.ODVR_FIXTURE_LEDGER);const ledger=new AcceptanceLedger(store);const record=await ledger.reserve(input,buffer,signed_at,'1','fixture@sha256:'+'1'.repeat(64));console.log(JSON.stringify({digest:record.digest,status:record.status}));}
catch(error){console.log(JSON.stringify({code:error.code ?? 'odvr_fixture_error'}));process.exitCode=1;}
