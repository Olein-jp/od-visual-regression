import { cp,lstat,readFile } from 'node:fs/promises';
const component=JSON.parse(await readFile('/runtime/component.json','utf8')).component;
await cp('node_modules','/runtime/node_modules',{recursive:true,verbatimSymlinks:true});
for(const workspace of ['runner',...(component==='dispatcher' ? ['dispatcher']:[])]){const path='apps/'+workspace+'/node_modules';try{if((await lstat(path)).isDirectory())await cp(path,'/runtime/'+path,{recursive:true,verbatimSymlinks:true});}catch(error){if(error.code!=='ENOENT')throw error;}}
