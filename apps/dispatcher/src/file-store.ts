import { mkdir, open, readFile, rename, rm } from 'node:fs/promises';
import { join, isAbsolute } from 'node:path';
import { randomUUID } from 'node:crypto';
import { setTimeout as wait } from 'node:timers/promises';
import { checkedRecord, checkedCounter, emptyCounter, ledgerKey } from './ledger.js';
import type { Acceptance, LedgerStore, Mutation, SiteCounter } from './types.js';
type State={storage_version:1;records:Record<string,Acceptance>;counters:Record<string,SiteCounter>};
/** 障害・restart・独立プロセスfixture用の永続adapter。本番はFirestoreを使用する。 */
export class FileStore implements LedgerStore {
  readonly #path:string;
  readonly #lock:string;
  constructor(directory:string,readonly clock=Date.now){if(!isAbsolute(directory))throw new Error('台帳fixtureの保存先を確認してください。');this.#path=join(directory,'ledger.json');this.#lock=join(directory,'ledger.lock');}
  async #read():Promise<State>{try{const value=JSON.parse(await readFile(this.#path,'utf8'));if(value.storage_version!==1 || Object.keys(value).sort().join()!=='counters,records,storage_version')throw new Error();return value;}catch(error){if((error as NodeJS.ErrnoException).code==='ENOENT')return {storage_version:1,records:{},counters:{}};throw new Error('台帳fixtureの保存状態を確認してください。');}}
  async #acquire():Promise<void>{
    const deadline=Date.now()+5000;
    for(;;){
      try{await mkdir(this.#lock,{mode:0o700});const owner=await open(join(this.#lock,'owner'),'wx',0o600);try{await owner.writeFile(String(process.pid));await owner.sync();}finally{await owner.close();}return;}
      catch(error){if((error as NodeJS.ErrnoException).code!=='EEXIST')throw error;}
      // 不明なlockを横取りしない。fixture transaction中の強制終了は試験側で再初期化する。
      if(Date.now()>=deadline)throw new Error('台帳fixtureのlockを確認してください。');await wait(10);
    }
  }
  async transaction<R>(site:string,uuid:string,change:(record:Acceptance|null,counter:SiteCounter)=>Mutation<R>):Promise<R>{
    await this.#acquire();const temporary=this.#path+'.'+randomUUID()+'.tmp';
    try{const state=await this.#read();const key=ledgerKey(site,uuid);const counterKey=ledgerKey(site,'counter');const mutation=change(state.records[key] ? checkedRecord(state.records[key]):null,state.counters[counterKey] ? checkedCounter(state.counters[counterKey]):emptyCounter());
      if(mutation.record)state.records[key]=checkedRecord(mutation.record);else delete state.records[key];state.counters[counterKey]=checkedCounter(mutation.counter);
      const file=await open(temporary,'wx',0o600);try{await file.writeFile(JSON.stringify(state));await file.sync();}finally{await file.close();}await rename(temporary,this.#path);return mutation.value;
    }finally{await rm(temporary,{force:true});await rm(this.#lock,{recursive:true,force:true});}
  }
  async get(site:string,uuid:string):Promise<Acceptance|null>{const state=await this.#read();const value=state.records[ledgerKey(site,uuid)];return value ? checkedRecord(value):null;}
  async scan(_cursor:string|null,limit:number):Promise<{records:Acceptance[];cursor:string|null}>{const state=await this.#read();return {records:Object.values(state.records).map(checkedRecord).filter(record=>record.next_action_at<=this.clock()).sort((a,b)=>a.next_action_at-b.next_action_at).slice(0,limit),cursor:null};}
  async health():Promise<void>{await this.#read();}
  async secretCursor():Promise<string|null>{try{const value=JSON.parse(await readFile(this.#path+'.cursor','utf8'));return typeof value==='string' ? value:null;}catch(error){if((error as NodeJS.ErrnoException).code==='ENOENT')return null;throw error;}}
  async saveSecretCursor(cursor:string|null):Promise<void>{const file=await open(this.#path+'.cursor','w',0o600);try{await file.writeFile(JSON.stringify(cursor));await file.sync();}finally{await file.close();}}
  async close():Promise<void>{}
}
