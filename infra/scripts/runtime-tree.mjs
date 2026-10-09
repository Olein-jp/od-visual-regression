import { cp,mkdir,readFile,writeFile } from 'node:fs/promises';
const component=process.argv[2];if(!['runner','dispatcher'].includes(component))throw new Error('componentを確認してください。');
const files=['packages/shared/package.json','packages/shared/dist','packages/schemas/package.json','packages/schemas/validate.mjs','packages/schemas/validate.d.mts','packages/schemas/src','apps/runner/package.json','apps/runner/dist',...(component==='dispatcher' ? ['apps/dispatcher/package.json','apps/dispatcher/dist']:[])];
for(const file of files){await mkdir('/runtime/'+file.split('/').slice(0,-1).join('/'),{recursive:true});await cp(file,'/runtime/'+file,{recursive:true});}
await mkdir('/runtime/infra/local',{recursive:true});for(const file of ['launcher.mjs','worker.mjs','check.mjs','initialize.mjs','relay.mjs','smoke.mjs','probe.mjs'])await cp('infra/local/'+file,'/runtime/infra/local/'+file);
const packageJSON={name:'odvr-runtime',private:true,type:'module',workspaces:['apps/*','packages/*']};await writeFile('/runtime/package.json',JSON.stringify(packageJSON));
const selected=component==='dispatcher' ? ['@odvr/dispatcher','@odvr/runner','@odvr/shared','@odvr/schemas']:['@odvr/runner','@odvr/shared','@odvr/schemas'];
// 元lockに固定された依存を選択してインストールする。runtimeへ開発依存は持ち込まない。
await writeFile('/runtime/component.json',JSON.stringify({component,selected,playwright:JSON.parse(await readFile('node_modules/playwright/package.json','utf8')).version}));
