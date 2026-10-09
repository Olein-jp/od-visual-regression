/** 固定fixtureの検証。秘密・生HTTP本文を出力しない。 */
import assert from 'node:assert/strict';
import { readFile,lstat,readdir } from 'node:fs/promises';
import { DestinationPolicy } from '@odvr/runner/security/destination-policy';
import { PinnedHttpClient,verifyPeer } from '@odvr/runner/security/pinned-http-client';
import { localRegistration } from './check.mjs';
const config=await localRegistration('/etc/odvr/runner.json');
const site=Object.values(config.sites)[0];
const options={profile:'local',captureOrigins:[config.local_destination.origin],controlBase:site.callback_base,localDestination:config.local_destination};
const policy=new DestinationPolicy(options);const client=new PinnedHttpClient({policy,ca:await readFile('/etc/odvr/fixture-ca.pem'),runDeadline:Date.now()+30000});
try{const response=await client.request({url:site.callback_base+'/runs/00000000-0000-4000-8000-000000000001/manifest',purpose:'manifest',auth:{kind:'bearer',token:'x'.repeat(43)}});assert.equal(response.status,401);assert.equal(JSON.parse(response.body).code,'odvr_runner_unauthorized');assert.equal(new Map(response.headers).get('x-odvr-raw-multipart'),'available');
 for(const url of ['https://wordpress.fixture.test:8444/','https://172.30.238.21:8443/','http://localhost/'])assert.throws(()=>policy.validate(url,'capture'));
 const pinned=await policy.resolve(config.local_destination.origin,'capture',()=>assert.fail('固定先を再解決した'));
 assert.throws(()=>verifyPeer({remoteAddress:'172.30.238.21',remotePort:8443},pinned));assert.throws(()=>verifyPeer({remoteAddress:pinned.address,remotePort:8444},pinned));
 assert.throws(()=>new DestinationPolicy({...options,profile:'cloud'}));const previous=process.env.CLOUD_RUN_JOB;try{process.env.CLOUD_RUN_JOB='fixture';assert.throws(()=>new DestinationPolicy(options));}finally{if(previous===undefined)delete process.env.CLOUD_RUN_JOB;else process.env.CLOUD_RUN_JOB=previous;}
 for(const directory of ['runs','shared'])for(const name of await readdir('/run/odvr/secrets/'+directory)){const info=await lstat('/run/odvr/secrets/'+directory+'/'+name);assert.equal(info.mode&0o777,0o600);assert.equal(info.uid,1000);}
 assert.match(await readFile('/proc/mounts','utf8'),/tmpfs \/run\/odvr\/secrets tmpfs/);assert.equal(process.getuid(),1000);
 process.stdout.write('固定HTTPS・別Origin/IP/port拒否・cloud拒否・tmpfs/0600を確認しました。\n');
}finally{client.close();}
