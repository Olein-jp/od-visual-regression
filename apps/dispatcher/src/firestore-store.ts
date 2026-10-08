import { createHash } from 'node:crypto';
import { Firestore } from '@google-cloud/firestore';
import { checkedRecord, checkedCounter, emptyCounter, ledgerKey } from './ledger.js';
import type { Acceptance, LedgerStore, Mutation, SiteCounter } from './types.js';
/** 固定project/databaseの二文書を同じtransactionで確定する。外部APIを呼ばない。 */
export class FirestoreStore implements LedgerStore {
  readonly #db:Firestore;
  constructor(project:string,database:string,emulator?:{host:string;port:number},readonly clock=Date.now){
    if(!/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/.test(project) || !/^(?:\(default\)|[a-z][a-z0-9-]{0,61}[a-z0-9])$/.test(database))throw new Error('台帳の固定設定を確認してください。');
    this.#db=new Firestore({projectId:project,databaseId:database,host:emulator?.host ?? 'firestore.googleapis.com',port:emulator?.port ?? 443,ssl:!emulator,ignoreUndefinedProperties:false});
  }
  async transaction<R>(site:string,uuid:string,change:(record:Acceptance|null,counter:SiteCounter)=>Mutation<R>):Promise<R> {
    const ref=this.#db.collection('odvr_acceptance').doc(ledgerKey(site,uuid));
    const counterRef=this.#db.collection('odvr_sites').doc(createHash('sha256').update(site).digest('hex'));
    return this.#db.runTransaction(async transaction=>{
      const [snapshot,counters]=await transaction.getAll(ref,counterRef);
      const mutation=change(snapshot.exists ? checkedRecord(snapshot.data()):null,counters.exists ? checkedCounter(counters.data()):emptyCounter());
      if(mutation.record)transaction.set(ref,checkedRecord(mutation.record));else if(snapshot.exists)transaction.delete(ref);
      transaction.set(counterRef,checkedCounter(mutation.counter));
      return mutation.value;
    },{maxAttempts:5});
  }
  async get(site:string,uuid:string):Promise<Acceptance|null> { const snapshot=await this.#db.collection('odvr_acceptance').doc(ledgerKey(site,uuid)).get();return snapshot.exists ? checkedRecord(snapshot.data()):null; }
  async scan(cursor:string|null,limit:number):Promise<{records:Acceptance[];cursor:string|null}> {
    if(!Number.isInteger(limit) || limit<1 || limit>100 || (cursor!==null && !/^[a-f0-9]{64}$/.test(cursor)))throw new Error('台帳のページ指定を確認してください。');
    const result=await this.#db.collection('odvr_acceptance').where('next_action_at','<=',this.clock()).orderBy('next_action_at').limit(limit).get();
    return {records:result.docs.map(item=>checkedRecord(item.data())),cursor:null};
  }
  async health():Promise<void> { await this.#db.collection('odvr_worker').doc('health').get(); }
  async secretCursor():Promise<string|null>{const value=(await this.#db.collection('odvr_worker').doc('cursor').get()).data()?.secret_cursor;return typeof value==='string' ? value:null;}
  async saveSecretCursor(cursor:string|null):Promise<void>{await this.#db.collection('odvr_worker').doc('cursor').set({storage_version:1,secret_cursor:cursor});}
  async close():Promise<void> { await this.#db.terminate(); }
}
