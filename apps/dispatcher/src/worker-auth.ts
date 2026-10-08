import { timingSafeEqual } from 'node:crypto';
import { OAuth2Client } from 'google-auth-library';
import { readPrivateFile } from '@odvr/runner/jobs/job-config';
import type { DispatcherConfig } from './config.js';
export class WorkerAuth {
 readonly #oauth=new OAuth2Client();
 constructor(readonly config:DispatcherConfig){}
 async authenticate(headers:readonly string[]):Promise<boolean>{
  const authorization=[];for(let i=0;i<headers.length;i+=2)if(headers[i].toLowerCase()==='authorization')authorization.push(headers[i+1]);
  if(authorization.length!==1 || !/^Bearer [A-Za-z0-9._-]{32,8192}$/.test(authorization[0]))return false;
  const token=authorization[0].slice(7);
  try{
   if(this.config.profile==='local'){const bytes=await readPrivateFile(this.config.local!.worker_key_file,128,true);try{return bytes.length===token.length && /^[A-Za-z0-9_-]{43}$/.test(bytes.toString('ascii')) && timingSafeEqual(bytes,Buffer.from(token));}finally{bytes.fill(0);}}
   const payload=(await this.#oauth.verifyIdToken({idToken:token,audience:this.config.scheduler.audience})).getPayload();return !!payload && ['accounts.google.com','https://accounts.google.com'].includes(payload.iss) && payload.sub===this.config.scheduler.subject && payload.email===this.config.scheduler.email && payload.email_verified===true && payload.aud===this.config.scheduler.audience;
  }catch{return false;}
 }
}
