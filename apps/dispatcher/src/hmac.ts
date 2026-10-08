import { createHmac, timingSafeEqual } from 'node:crypto';
import { TextDecoder } from 'node:util';
import { validateContract } from '@odvr/schemas';
import type { DispatchConnectionTestRequest, DispatchRequest } from '@odvr/shared';
import { DispatchError } from './types.js';
/** JSONの重複キーを深い位置でも拒否する。認証用raw bytesは再encodeしない。 */
export function strictJSON(body:Buffer):Record<string,unknown>{
  if(body.length>16384)throw new DispatchError('odvr_payload_too_large',413);
  let text:string;try{text=new TextDecoder('utf-8',{fatal:true}).decode(body);}catch{throw new DispatchError('odvr_dispatch_unauthorized',401);}
  let offset=0;let tokens=0;
  const whitespace=()=>{while(/[ \t\r\n]/.test(text[offset] ?? 'x'))offset++;};
  const string=():string=>{const start=offset++;let escape=false;while(offset<text.length){const char=text[offset++];if(!escape && char==='"')return JSON.parse(text.slice(start,offset));if(!escape && char==='\\')escape=true;else escape=false;}throw new Error();};
  const value=(depth:number):void=>{if(depth>10 || ++tokens>2048)throw new Error();whitespace();const char=text[offset];
    if(char==='"'){string();return;}
    if(char==='{' || char==='['){const object=char==='{';offset++;whitespace();const end=object ? '}':']';const keys=new Set<string>();if(text[offset]===end){offset++;return;}for(;;){whitespace();if(object){if(text[offset]!=='"')throw new Error();const key=string();if(keys.has(key))throw new Error();keys.add(key);whitespace();if(text[offset++]!==':')throw new Error();}value(depth+1);whitespace();if(text[offset]===end){offset++;return;}if(text[offset++]!==',')throw new Error();} }
    const match=/^(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/.exec(text.slice(offset));if(!match)throw new Error();offset+=match[0].length;
  };
  try{value(0);whitespace();if(offset!==text.length)throw new Error();const parsed=JSON.parse(text);if(!parsed || typeof parsed!=='object' || Array.isArray(parsed))throw new Error();return parsed;}
  catch{throw new DispatchError('odvr_dispatch_unauthorized',401);}
}
export interface HmacSite { callback_base:string;shared_versions:readonly string[];rotation_until:number|null }
export class HmacVerifier {
  constructor(readonly sites:Readonly<Record<string,HmacSite>>,readonly readSecret:(resource:string)=>Promise<Buffer>,readonly clock=Date.now){}
  async authenticate(siteId:unknown,body:Buffer,headers:readonly string[]):Promise<{site:HmacSite;signedAt:number}> {
    const single=(name:string)=>{const values=[];for(let i=0;i<headers.length;i+=2)if(headers[i].toLowerCase()===name)values.push(headers[i+1]);if(values.length!==1)throw new DispatchError('odvr_dispatch_unauthorized',401);return values[0];};
    const timestamp=single('x-odvr-timestamp');const signature=single('x-odvr-signature');
    if(!/^[1-9][0-9]{8,10}$/.test(timestamp) || !/^[a-f0-9]{64}$/.test(signature) || Math.abs(Math.floor(this.clock()/1000)-Number(timestamp))>300 || typeof siteId!=='string' || !Object.hasOwn(this.sites,siteId))throw new DispatchError('odvr_dispatch_unauthorized',401);
    const site=this.sites[siteId];let accepted=false;
    const versions=site.rotation_until!==null && this.clock()<=site.rotation_until ? site.shared_versions:site.shared_versions.slice(0,1);
    for(const resource of versions){let secret:Buffer;try{secret=await this.readSecret(resource);}catch{throw new DispatchError('odvr_dispatch_unavailable',503,true);}try{if(secret.length<32 || secret.length>4096)throw new DispatchError('odvr_dispatch_unavailable',503,true);const expected=createHmac('sha256',secret).update(timestamp+'\n').update(body).digest();accepted=timingSafeEqual(expected,Buffer.from(signature,'hex')) || accepted;}finally{secret.fill(0);}}
    if(!accepted)throw new DispatchError('odvr_dispatch_unauthorized',401);return {site,signedAt:Number(timestamp)*1000};
  }
  payload(body:Buffer,site:HmacSite,diagnosis=false):DispatchRequest|DispatchConnectionTestRequest {
    try{const input=validateContract<DispatchRequest|DispatchConnectionTestRequest>(diagnosis ? 'dispatch-connection-test-request':'dispatch-request',strictJSON(body));if(input.callback_base!==site.callback_base)throw new Error();return input;}
    catch{throw new DispatchError('odvr_invalid_payload',400);}
  }
}
