import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { PNG } from 'pngjs';
import { DEFAULT_DEVICES, DEFAULT_SETTINGS } from '@odvr/shared';
import { createContext, BROWSER_LAUNCH_OPTIONS } from '../dist/browser/context-factory.js';
import { capturePage } from '../dist/browser/screenshot.js';
import { compareImages } from '../dist/visual/compare.js';
test('Chromiumで全ページ撮影・マスク・変更比較を行う', async () => {
  const browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
  try {
    const context = await createContext(browser, DEFAULT_DEVICES[0]);
    const page = await context.newPage();
    await page.setContent('<style>body{margin:0}.page{height:1500px;background:white}.dynamic{width:200px;height:100px}</style><div class="page"><div class="dynamic" data-odvr-ignore>初期表示</div></div>');
    const baseline = await capturePage(page, DEFAULT_SETTINGS);
    const dimensions = PNG.sync.read(baseline);
    assert.equal(dimensions.width, 1440);
    assert.equal(dimensions.height, 1500);
    await page.locator('.dynamic').evaluate(node => node.textContent = '更新された時刻');
    const masked = await capturePage(page, DEFAULT_SETTINGS);
    assert.equal(compareImages(baseline, masked, DEFAULT_SETTINGS).diff_pixels, 0);
    await page.locator('.page').evaluate(node => node.style.background = 'black');
    const changed = await capturePage(page, DEFAULT_SETTINGS);
    assert.equal(compareImages(baseline, changed, DEFAULT_SETTINGS).status, 'CHANGED');
    await context.close();
  } finally { await browser.close(); }
});

test('Browserのリクエストガードが内部ホストへの遷移を拒否する', async () => {
  const { installNetworkGuard } = await import('../dist/security/network-guard.js');
  const browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
  try {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    const diagnostics = await installNetworkGuard(context, ['http://127.0.0.1:12345']);
    const page = await context.newPage();
    await assert.rejects(page.goto('http://127.0.0.1:12345', { timeout: 2000 }));
    assert.equal(diagnostics.navigation_error_code, 'IP_BLOCKED');
    assert.equal(diagnostics.blocked_resource_reasons.IP_BLOCKED, 1);
    await context.close();
  } finally { await browser.close(); }
});

test('Runは対象の失敗後も継続し、履歴の上書きを拒否する', async () => {
  const { mkdtemp, readFile, rm } = await import('node:fs/promises');
  const { tmpdir } = await import('node:os');
  const { join } = await import('node:path');
  const { executeRun } = await import('../dist/jobs/execute-run.js');
  const directory = await mkdtemp(join(tmpdir(), 'odvr-test-'));
  const output = join(directory, 'run');
  const manifest = { targets: [{ id: 1, label: '拒否対象', url: 'http://127.0.0.1/' }, { id: 2, label: '次の対象', url: 'http://127.0.0.1/' }], devices: [DEFAULT_DEVICES[0]], settings: DEFAULT_SETTINGS, allowed_origins: ['http://127.0.0.1'] };
  try {
    const report = await executeRun(manifest, output);
    assert.equal(report.total_snapshots, 2);
    assert.equal(report.error_snapshots, 2);
    assert.ok(report.snapshots.every(snapshot => snapshot.error_code === 'IP_BLOCKED' && snapshot.http_status === null && snapshot.duration_ms >= 0));
    assert.equal(JSON.parse(await readFile(join(output, 'result.json'))).snapshots.length, 2);
    await assert.rejects(executeRun(manifest, output));
    await assert.rejects(executeRun(manifest, output, output), /別のディレクトリ/);
  } finally { await rm(directory, { recursive: true, force: true }); }
});

test('Chromiumの画像読込失敗をIMAGE_LOAD_FAILEDとして返す', async () => {
  const browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
  try {
    const context = await createContext(browser, DEFAULT_DEVICES[0]);
    const page = await context.newPage();
    await page.setContent('<img src="data:image/png;base64,Ym9ya2Vu">');
    await assert.rejects(capturePage(page, DEFAULT_SETTINGS), error => error.code === 'IMAGE_LOAD_FAILED');
    await context.close();
  } finally { await browser.close(); }
});

// 仮想Originの応答だけを返す。実ネットワークにも本番ガードの変更にも依存しない。
async function fixtureContext(browser, name, options = {}) {
  const { readFile } = await import('node:fs/promises');
  const context = await createContext(browser, options.device ?? { ...DEFAULT_DEVICES[0], viewport_width: 320, viewport_height: 240 });
  const blue = new PNG({ width: 80, height: 80 });
  for (let i = 0; i < blue.data.length; i += 4) blue.data.set([0, 0, 255, 255], i);
  await context.route('https://fixture.odvr.test/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (options.resources?.[path]) return options.resources[path](route);
    if (path === '/blue.png') return route.fulfill({ contentType: 'image/png', body: PNG.sync.write(blue) });
    if (path === '/style.css') return route.fulfill({ contentType: 'text/css', body: 'body{margin:0}#marker{width:40px;height:40px;background:#0000ff;animation:change 0.1s infinite alternate}#ignored{width:40px;height:40px;background:red}@keyframes change{to{background:red}}' });
    if (path === '/') return route.fulfill({ contentType: 'text/html', body: await readFile(new URL(`./fixtures/${name}.html`, import.meta.url)), headers: options.headers });
    return route.abort();
  });
  const page = await context.newPage();
  await page.goto(`https://fixture.odvr.test/${options.query ?? ''}`, { waitUntil: 'domcontentloaded' });
  return { context, page };
}

function pixel(buffer, x, y) {
  const png = PNG.sync.read(buffer);
  return Array.from(png.data.subarray((y * png.width + x) * 4, (y * png.width + x) * 4 + 4));
}

function gate() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}

const fixtureSettings = { ...DEFAULT_SETTINGS, image_timeout_ms: 2000 };

test('スクロールで画像を読み込み、最上部に戻った内容を撮影する', async () => {
  const browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
  try {
    const { page } = await fixtureContext(browser, 'lazy');
    const buffer = await capturePage(page, fixtureSettings);
    assert.deepEqual(pixel(buffer, 10, 10), [0, 255, 0, 255]);
    assert.deepEqual(pixel(buffer, 10, 1210), [0, 0, 255, 255]);
    assert.equal(await page.evaluate(() => window.scrollY), 0);
  } finally { await browser.close(); }
});

test('Lazy Load無効ではスクロールによる画像読込を開始しない', async () => {
  const browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
  try {
    const { page } = await fixtureContext(browser, 'lazy');
    const buffer = await capturePage(page, { ...fixtureSettings, lazy_load: false });
    assert.deepEqual(pixel(buffer, 10, 10), [255, 0, 0, 255]);
    assert.deepEqual(pixel(buffer, 10, 1210), [255, 0, 0, 255]);
    assert.equal(await page.locator('#lazy').getAttribute('src'), null);
    assert.equal(await page.evaluate(() => window.scrollY), 0);
  } finally { await browser.close(); }
});

test('高さが増え続けるページも待機timeoutで終了し最上部に戻る', async () => {
  const browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
  try {
    const { page } = await fixtureContext(browser, 'lazy', { query: '?grow' });
    await assert.rejects(capturePage(page, { ...fixtureSettings, image_timeout_ms: 250 }), error => error.code === 'IMAGE_LOAD_FAILED');
    assert.equal(await page.evaluate(() => window.scrollY), 0);
  } finally { await browser.close(); }
});

for (const resource of ['image', 'font']) {
  for (const outcome of ['loaded', 'timeout']) {
    test(`${resource}の遅延読込: ${outcome}`, { timeout: 10000 }, async () => {
      const { readFile, readdir } = await import('node:fs/promises');
      const { createRequire } = await import('node:module');
      const { dirname, join } = await import('node:path');
      const assets = join(dirname(createRequire(import.meta.url).resolve('playwright-core/package.json')), 'lib/vite/recorder/assets');
      const font = await readFile(join(assets, (await readdir(assets)).find(name => name.endsWith('.ttf'))));
      const browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
      const requested = gate(), release = gate();
      const path = resource === 'image' ? '/blue.png' : '/font.ttf';
      try {
        const { page } = await fixtureContext(browser, 'resources', { resources: {
          '/font.ttf': route => route.fulfill({ contentType: 'font/ttf', body: font }),
          [path]: async route => {
            requested.resolve();
            await release.promise;
            if (outcome === 'timeout') return route.abort();
            if (resource === 'font') return route.fulfill({ contentType: 'font/ttf', body: font });
            const png = new PNG({ width: 80, height: 80 });
            for (let i = 0; i < png.data.length; i += 4) png.data.set([0, 0, 255, 255], i);
            return route.fulfill({ contentType: 'image/png', body: PNG.sync.write(png) });
          },
        } });
        await requested.promise;
        await page.waitForFunction(kind => kind === 'image' ? !document.images[0].complete : document.fonts.status === 'loading', resource);
        let settled = false;
        const capture = capturePage(page, { ...fixtureSettings, lazy_load: false, image_timeout_ms: outcome === 'timeout' ? 250 : 2000 });
        capture.then(() => { settled = true; }, () => { settled = true; });
        if (outcome === 'timeout') {
          await assert.rejects(capture, error => error.code === 'IMAGE_LOAD_FAILED');
        } else {
          // 撮影が待機中であることを確認してから、応答を明示的に解放する。
          await page.waitForTimeout(100);
          assert.equal(settled, false);
          release.resolve();
          const buffer = await capture;
          assert.deepEqual(pixel(buffer, 10, 10), [0, 0, 255, 255]);
          assert.equal(await page.evaluate(() => document.fonts.check('16px Fixture')), true);
          assert.equal(await page.evaluate(() => document.images[0].naturalWidth), 80);
        }
      } finally { release.resolve(); await browser.close(); }
    });
  }
}

test('ignore selectorと共通属性が両方ともピクセルをマスクする', async () => {
  const browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
  try {
    const context = await createContext(browser, DEFAULT_DEVICES[0]);
    const page = await context.newPage();
    await page.setContent('<style>body{margin:0}div{width:40px;height:40px;background:red}</style><div data-odvr-ignore></div><div class="ignore"></div><div id="visible"></div>');
    const settings = { ...fixtureSettings, ignore_selectors: ['.ignore'] };
    const before = await capturePage(page, settings);
    assert.deepEqual(pixel(before, 10, 10), [255, 0, 255, 255]);
    assert.deepEqual(pixel(before, 10, 50), [255, 0, 255, 255]);
    assert.deepEqual(pixel(before, 10, 90), [255, 0, 0, 255]);
    await page.locator('[data-odvr-ignore],.ignore').evaluateAll(nodes => nodes.forEach(node => node.style.background = 'blue'));
    assert.equal(compareImages(before, await capturePage(page, settings), settings).diff_pixels, 0);
  } finally { await browser.close(); }
});

test('Deviceのviewport・DPR・mobile・touch・User Agentを反映しCSS pixelで撮影する', async () => {
  const browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
  try {
    for (const device of [
      { ...DEFAULT_DEVICES[0], viewport_width: 320, viewport_height: 240, device_scale_factor: 2, user_agent: 'ODVR Desktop Fixture' },
      { ...DEFAULT_DEVICES[2], device_scale_factor: 3, user_agent: 'ODVR Mobile Fixture' },
    ]) {
      const context = await createContext(browser, device);
      const page = await context.newPage();
      await page.setContent('<body></body>');
      assert.equal(await page.evaluate(() => innerWidth), device.is_mobile ? 980 : device.viewport_width);
      await page.setContent('<meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;background:blue}</style>');
      const properties = await page.evaluate(() => ({ width: innerWidth, height: innerHeight, dpr: devicePixelRatio, touch: navigator.maxTouchPoints > 0, ua: navigator.userAgent, mobile: matchMedia('(pointer: coarse)').matches }));
      assert.deepEqual(properties, { width: device.viewport_width, height: device.viewport_height, dpr: device.device_scale_factor, touch: device.has_touch, ua: device.user_agent, mobile: device.is_mobile });
      const png = PNG.sync.read(await capturePage(page, fixtureSettings));
      assert.equal(png.width, device.viewport_width);
      assert.equal(png.height, device.viewport_height);
      await context.close();
    }
  } finally { await browser.close(); }
});

test('厳格なCSP下でも外部CSSを読み込みアニメーションを抑止して撮影する', async () => {
  const browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
  try {
    const { page } = await fixtureContext(browser, 'csp', { headers: { 'content-security-policy': "default-src 'none'; style-src 'self'; script-src 'none'" } });
    const buffer = await capturePage(page, fixtureSettings);
    assert.deepEqual(pixel(buffer, 10, 10), [0, 0, 255, 255]);
    assert.deepEqual(pixel(buffer, 10, 50), [255, 0, 255, 255]);
    assert.equal(await page.locator('#marker').evaluate(node => getComputedStyle(node).animationName), 'none');
    assert.equal(await page.evaluate(() => document.body.dataset.inlineScript), undefined);
  } finally { await browser.close(); }
});


// 実TLSを単一RFC1918先へ固定する。公開インターネットへ試験通信を送らない。
async function transportFixture(t, handler, limits = {}) {
  const { createServer } = await import('node:https');
  const { createServer: tcpServer } = await import('node:net');
  const { networkInterfaces } = await import('node:os');
  const { readFileSync } = await import('node:fs');
  const { DestinationPolicy } = await import('../dist/security/destination-policy.js');
  const { PinnedHttpClient } = await import('../dist/security/pinned-http-client.js');
  const { installNetworkGuard } = await import('../dist/security/network-guard.js');
  const address = Object.values(networkInterfaces()).flat().find(x => x.family === 'IPv4' && !x.internal && /^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/.test(x.address))?.address;
  assert.ok(address,'実RFC1918 fixtureが必要です。skipしません。');
  const key = readFileSync(new URL('./fixtures/tls/fixture-key.pem',import.meta.url));
  const cert = readFileSync(new URL('./fixtures/tls/fixture-cert.pem',import.meta.url));
  const seen = [], sockets = new Set();
  let forbiddenAccepted = 0, forbiddenUdp = 0;
  const {createSocket} = await import('node:dgram');
  const udp = createSocket('udp4');
  udp.on('message',() => {forbiddenUdp++;});
  const forbidden = tcpServer(socket => { forbiddenAccepted++; socket.destroy(); });
  await new Promise(resolve => forbidden.listen(0,address,resolve));
  const forbiddenUrl = `https://${address}:${forbidden.address().port}/`;
  await new Promise(resolve => udp.bind(forbidden.address().port,address,resolve));
  const server = createServer({key,cert},(req,res) => { seen.push({path:req.url,headers:req.headers}); handler(req,res); });
  server.on('connection',socket => { sockets.add(socket); socket.once('close',() => sockets.delete(socket)); });
  await new Promise(resolve => server.listen(0,address,resolve));
  const port = server.address().port, origin = `https://fixture.test:${port}`;
  const policy = new DestinationPolicy({captureOrigins:[origin],profile:'local',localDestination:{origin,address,port}});
  const client = new PinnedHttpClient({policy,ca:cert,limits});
  const browser = await chromium.launch(BROWSER_LAUNCH_OPTIONS);
  const contexts = [];
  async function context(auth, targetKey = '1:1') {
    const value = await createContext(browser,{...DEFAULT_DEVICES[0],viewport_width:320,viewport_height:240});
    contexts.push(value);
    const diagnostics = await installNetworkGuard(value,[origin],{client,targetKey,auth});
    const page = await value.newPage();
    return {context:value,page,diagnostics};
  }
  t.after(async () => { client.close(); await browser.close(); for (const socket of sockets) socket.destroy(); await new Promise(resolve => server.close(resolve)); await new Promise(resolve => forbidden.close(resolve)); await new Promise(resolve => udp.close(resolve)); });
  return {origin,seen,client,context,forbiddenUrl,forbiddenAccepted:() => forbiddenAccepted,forbiddenUdp:() => forbiddenUdp,sockets};
}

test('固定TLS取得でBasic成功・失敗、Cookie多値・path/secure、圧縮・CSPを維持する',async t => {
  const expected = `Basic ${Buffer.from('fixture-user:fixture-password').toString('base64')}`;
  const {gzipSync} = await import('node:zlib');
  const f = await transportFixture(t,(req,res) => {
    if (req.headers.authorization !== expected) { res.writeHead(401,{'WWW-Authenticate':'Basic realm="fixture"'}); res.end('Unauthorized'); return; }
    if (req.url === '/') {
      res.writeHead(200,{'Content-Type':'text/html','Content-Encoding':'gzip','Set-Cookie':['root=one; Path=/; Secure; HttpOnly','scoped=two; Path=/scope; Secure','foreign=never; Domain=evil.test; Path=/'], 'Content-Security-Policy':"default-src 'none'; style-src 'self'; script-src 'none'; connect-src 'self'"});
      res.end(gzipSync('<link rel="stylesheet" href="/style.css"><script>window.untrusted=true</script><div>撮影</div>'));
    } else if (req.url === '/style.css') { res.writeHead(200,{'Content-Type':'text/css'}); res.end('body{margin:0;background:blue}'); }
    else { res.writeHead(200,{'Content-Type':'application/json'}); res.end(JSON.stringify(req.headers)); }
  });
  const auth = {kind:'basic',origin:f.origin,username:'fixture-user',password:'fixture-password'};
  const good = await f.context(auth);
  assert.equal((await good.page.goto(f.origin)).status(),200);
  assert.deepEqual(pixel(await capturePage(good.page,fixtureSettings),10,10),[0,0,255,255]);
  assert.equal(await good.page.evaluate(() => window.untrusted),undefined);
  const cookies = await good.context.cookies();
  assert.deepEqual(cookies.map(x => x.name).sort(),['root','scoped']);
  const headers = await good.page.evaluate(async () => (await fetch('/scope/echo',{headers:{Authorization:'Bearer page-secret','Metadata-Flavor':'Google'}})).json());
  assert.equal(headers.authorization,expected); assert.equal(headers['metadata-flavor'],undefined);
  assert.ok(headers.cookie.includes('root=one')); assert.ok(headers.cookie.includes('scoped=two'));
  const outside = await good.page.evaluate(async () => (await fetch('/echo')).json());
  assert.equal(outside.cookie,'root=one');
  await assert.rejects(good.page.goto(f.forbiddenUrl,{timeout:2000}));
  assert.equal(f.forbiddenAccepted(),0);
  assert.equal(good.diagnostics.blocked_resource_reasons.ORIGIN_BLOCKED,1);
  const bad = await f.context({...auth,password:'wrong'},'2:1');
  const response = await bad.page.goto(f.origin);
  assert.equal(response.status(),401);
  assert.ok(f.seen.some(x => x.headers.authorization === `Basic ${Buffer.from('fixture-user:wrong').toString('base64')}`));
  assert.ok(f.seen.every(x => x.headers.host === new URL(f.origin).host));
});

test('Context全HTTPのiframe・popup初回・worker・fetch/XHR・beacon・画像/CSSを固定取得する',async t => {
  const f = await transportFixture(t,(req,res) => {
    if (req.url === '/worker.js') { res.writeHead(200,{'Content-Type':'text/javascript'}); res.end("fetch('/worker-fetch').then(() => postMessage('done'))"); }
    else if (req.url === '/') { res.writeHead(200,{'Content-Type':'text/html'}); res.end('<link rel="stylesheet" href="/style.css"><iframe src="/frame"></iframe><img src="/image.svg">'); }
    else if (req.url === '/style.css') { res.writeHead(200,{'Content-Type':'text/css'}); res.end('body{background:blue}'); }
    else if (req.url === '/image.svg') { res.writeHead(200,{'Content-Type':'image/svg+xml'}); res.end('<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'); }
    else { res.writeHead(200,{'Content-Type':'text/html'}); res.end('fixture'); }
  });
  const {page,context,diagnostics} = await f.context();
  await page.goto(f.origin,{waitUntil:'load'});
  const popupPromise = context.waitForEvent('page');
  await page.evaluate(() => window.open('/popup'));
  const popup = await popupPromise; await popup.waitForLoadState('domcontentloaded');
  await page.evaluate(async () => {
    await fetch('/fetch');
    await new Promise(resolve => { const xhr = new XMLHttpRequest(); xhr.open('GET','/xhr'); xhr.onload=resolve; xhr.send(); });
    await new Promise((resolve,reject) => { const worker = new Worker('/worker.js'); worker.onmessage=() => { worker.terminate(); resolve(); }; worker.onerror=reject; });
    navigator.sendBeacon('/beacon','fixture-only');
  });
  await page.waitForFunction(() => document.images[0].complete);
  // Beacon完了を実serverの受信で確認する。
  for (let i=0;i<100 && !f.seen.some(x => x.path === '/beacon');i++) await new Promise(resolve => setTimeout(resolve,10));
  for (const path of ['/frame','/popup','/worker.js','/worker-fetch','/fetch','/xhr','/beacon','/image.svg','/style.css']) assert.ok(f.seen.some(x => x.path===path),path);
  assert.equal(diagnostics.blocked_resource_count,0);
  assert.equal(f.forbiddenAccepted(),0);
});

test('未許可・Metadataへの全入口とredirectを拒否しWS/SW/WebRTC/WebTransportを抑止する',async t => {
  const f = await transportFixture(t,(req,res) => {
    if (req.url === '/meta') { res.writeHead(200,{'Content-Type':'text/html'});res.end(`<meta http-equiv="refresh" content="0;url=${f.forbiddenUrl}">`); }
    else if (req.url === '/js') { res.writeHead(200,{'Content-Type':'text/html'});res.end(`<script>location.href=${JSON.stringify(f.forbiddenUrl)}</script>`); }
    else if (req.url === '/redirect') { res.writeHead(302,{Location:f.forbiddenUrl}); res.end(); }
    else if (req.url === '/worker.js') { res.writeHead(200,{'Content-Type':'text/javascript'}); res.end(`fetch(${JSON.stringify(f.forbiddenUrl)}).catch(async () => { const wsFailed=await new Promise(resolve => { const ws=new WebSocket(${JSON.stringify(f.forbiddenUrl.replace('https:','wss:'))}); ws.onerror=() => resolve(true); ws.onopen=() => {ws.close();resolve(false);}; }); let failed=false; try { const transport=new WebTransport(${JSON.stringify(f.forbiddenUrl)}); await transport.ready; transport.close(); } catch { failed=true; } postMessage({failed,wsFailed,rtc:typeof RTCPeerConnection}); })`); }
    else { res.writeHead(200,{'Content-Type':'text/html'}); res.end('fixture'); }
  });
  const {page,context,diagnostics} = await f.context();
  await page.goto(f.origin);
  await page.evaluate(async blocked => {
    await Promise.all([fetch(blocked).catch(() => {}),fetch('http://169.254.169.254/').catch(() => {}),fetch('http://metadata.google.internal/').catch(() => {})]);
    const frame = document.createElement('iframe'); frame.src=blocked; document.body.append(frame);
    const image = new Image(); image.src=blocked; document.body.append(image);
    navigator.sendBeacon(blocked,'fixture-only');
    window.open(blocked);
    new WebSocket(blocked.replace('https:','wss:'));
    window.protocols = [typeof RTCPeerConnection,typeof WebTransport];
    try { window.swResult=typeof await navigator.serviceWorker.register('/sw.js'); } catch { window.swResult='rejected'; }
    window.blobWorkerBlocked = await new Promise(resolve => { const source = URL.createObjectURL(new Blob(['postMessage(1)'],{type:'text/javascript'})); const worker=new Worker(source); worker.onerror=() => {URL.revokeObjectURL(source);worker.terminate();resolve(true);}; worker.onmessage=() => {URL.revokeObjectURL(source);worker.terminate();resolve(false);}; });
    window.workerProtocols = await new Promise((resolve,reject) => { const w=new Worker('/worker.js'); w.onmessage=e => {w.terminate();resolve(e.data);}; w.onerror=reject; });
  },f.forbiddenUrl);
  assert.deepEqual(await page.evaluate(() => window.protocols),['undefined','undefined']);
  assert.deepEqual(await page.evaluate(() => window.workerProtocols),{failed:true,wsFailed:true,rtc:'undefined'});
  assert.ok(['undefined','rejected'].includes(await page.evaluate(() => window.swResult)));
  assert.equal(context.serviceWorkers().length,0);
  assert.equal(await page.evaluate(() => window.blobWorkerBlocked),true);
  for(const path of ['/meta','/js']) { const denied=page.waitForEvent('requestfailed',request => request.url() === f.forbiddenUrl); await page.goto(f.origin+path,{waitUntil:'domcontentloaded'}).catch(error => assert.match(error.message,/interrupted|ERR_BLOCKED_BY_CLIENT/)); await denied; }
  await assert.rejects(page.goto(`${f.origin}/redirect`,{timeout:2000}));
  assert.equal(diagnostics.navigation_error_code,'REDIRECT_BLOCKED');
  assert.ok(diagnostics.blocked_resource_reasons.ORIGIN_BLOCKED>=3);
  assert.equal(diagnostics.blocked_resource_reasons.REDIRECT_BLOCKED,1);
  assert.equal(f.forbiddenAccepted(),0);
  assert.equal(f.forbiddenUdp(),0);
  assert.ok(!f.seen.some(x => x.path === '/sw.js'));
});

test('fulfillで欠落CORSを自動許可せず、明示CORSだけブラウザに許可する',async t => {
  const f = await transportFixture(t,(req,res) => {
    res.writeHead(200,req.url === '/allowed' ? {'Access-Control-Allow-Origin':'null'} : {}); res.end('fixture');
  });
  const {page} = await f.context();
  // about:blankのOriginはnull。取得先は同じ許可fixtureだがcross-originとなる。
  const result = await page.evaluate(async origin => {
    const outcomes = [];
    for (const path of ['/denied','/allowed']) try { outcomes.push(await (await fetch(origin+path)).text()); } catch { outcomes.push('blocked'); }
    return outcomes;
  },f.origin);
  assert.deepEqual(result,['blocked','fixture']);
});

test('Context終了・Target期限で取得中socketを破棄し、共有Runの別Contextは維持する',async t => {
  let started;
  const waiting = new Promise(resolve => {started=resolve;});
  const f = await transportFixture(t,(req,res) => { if(req.url === '/hang') started(); else {res.writeHead(200,{'Content-Type':'text/html'});res.end('fixture');} },{targetMs:800,requestMs:2000});
  const first = await f.context(undefined,'1:1');
  const navigation = first.page.goto(`${f.origin}/hang`).catch(() => {});
  await waiting;
  assert.equal(f.client.activeConnections,1);
  await first.context.close(); await navigation;
  for(let i=0;i<100 && f.client.activeConnections;i++) await new Promise(resolve => setTimeout(resolve,10));
  assert.equal(f.client.activeConnections,0);
  const second = await f.context(undefined,'2:1');
  assert.equal((await second.page.goto(f.origin)).status(),200);
  await assert.rejects(second.page.goto(`${f.origin}/hang`,{timeout:3000}));
  assert.equal(second.diagnostics.navigation_error_code,'NETWORK_TIMEOUT');
  assert.equal(f.client.activeConnections,0);
});
