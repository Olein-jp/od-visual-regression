import { randomUUID } from 'node:crypto';
import { PinnedHttpClient } from '@odvr/runner/security/pinned-http-client';
import { DestinationPolicy } from '@odvr/runner/security/destination-policy';
import type { DispatcherConfig } from './config.js';
/** 実在Runや秘密を使わず、WordPressの固定認証入口へ到達できることを調べる。 */
export async function probeCallback(config:DispatcherConfig,siteId:string):Promise<boolean>{
 const base=config.sites[siteId]?.callback_base;if(!base)return false;
 const client=new PinnedHttpClient({policy:new DestinationPolicy({controlBase:base,captureOrigins:config.local ? [config.local.callback_destination.origin]:[],profile:config.profile,localDestination:config.local?.callback_destination}),limits:{requestMs:5000,runMs:10000,runConnections:1,controlResponse:16384,runBytes:32768}});
 try{const response=await client.request({purpose:'manifest',url:base+'/runs/'+randomUUID()+'/manifest',headers:[['Accept','application/json']],auth:{kind:'bearer',token:'0'.repeat(43)}});if(response.status!==401)return false;const headers=new Map(response.headers.map(([name,value])=>[name.toLowerCase(),value]));if(headers.get('x-odvr-runner-gateway')!=='1' || headers.get('x-odvr-raw-multipart')!=='available' || headers.get('x-odvr-bearer-header')!=='present')return false;const body=JSON.parse(response.body.toString('utf8'));return body.schema_version===1 && body.code==='odvr_runner_unauthorized' && body.data?.status===401;}catch{return false;}finally{client.close();}
}
