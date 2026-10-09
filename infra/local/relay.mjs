import https from 'node:https';
import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { readPrivateFile } from '@odvr/runner/jobs/job-config';
if(process.getuid()===0 || process.env.K_SERVICE || process.env.CLOUD_RUN_JOB || process.env.CLOUD_RUN_EXECUTION)throw new Error('local relayの実行条件を確認してください。');
const config=JSON.parse((await readPrivateFile('/etc/odvr/relay.json',16384)).toString('utf8'));
if(Object.keys(config).sort().join()!=='dispatcher,wordpress' || !/^http:\/\/172\.30\.238\.10:80$/.test(config.wordpress) || config.dispatcher!=='http://dispatcher:8080')throw new Error('local relayの固定先を確認してください。');
const routes={'wordpress.fixture.test:8443':config.wordpress,'dispatcher.fixture.test:8443':config.dispatcher};
const server=https.createServer({key:await readPrivateFile('/run/odvr/secrets/relay-key',16384,true),cert:await readFile('/etc/odvr/fixture-ca.pem'),minVersion:'TLSv1.2',maxHeaderSize:32768,requestTimeout:30000,headersTimeout:10000},(request,response)=>{
 const upstream=routes[request.headers.host];if(!upstream || request.socket.servername!==request.headers.host.split(':')[0] || !request.url?.startsWith('/') || request.url.startsWith('//')){response.writeHead(400);response.end();return;}
 const headers={...request.headers};delete headers['proxy-authorization'];delete headers['proxy-connection'];delete headers.connection;headers['x-forwarded-proto']='https';let bytes=0;const fixed=new URL(upstream);const backend=http.request({hostname:fixed.hostname,port:fixed.port,path:request.url,method:request.method,headers,timeout:30000},incoming=>{response.writeHead(incoming.statusCode,incoming.rawHeaders);incoming.pipe(response);});const stop=()=>{backend.destroy();if(response.destroyed || response.writableEnded)return;if(!response.headersSent)response.writeHead(502);response.end();};backend.on('error',stop);backend.on('timeout',stop);request.on('data',chunk=>{bytes+=chunk.length;if(bytes>42*1024*1024){request.unpipe(backend);backend.destroy();response.writeHead(413);response.end();}});request.on('aborted',()=>backend.destroy());request.pipe(backend);
});server.listen(8443,'0.0.0.0');process.once('SIGTERM',()=>{server.close();server.closeAllConnections();});
