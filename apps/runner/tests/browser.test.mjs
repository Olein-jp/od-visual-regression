import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { PNG } from 'pngjs';
import { DEFAULT_DEVICES, DEFAULT_SETTINGS } from '@odvr/shared';
import { createContext } from '../dist/browser/context-factory.js';
import { capturePage } from '../dist/browser/screenshot.js';
import { compareImages } from '../dist/visual/compare.js';
test('Chromiumで全ページ撮影・マスク・変更比較を行う', async () => {
  const browser = await chromium.launch();
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
  const browser = await chromium.launch();
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
  const browser = await chromium.launch();
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
  const browser = await chromium.launch();
  try {
    const { page } = await fixtureContext(browser, 'lazy');
    const buffer = await capturePage(page, fixtureSettings);
    assert.deepEqual(pixel(buffer, 10, 10), [0, 255, 0, 255]);
    assert.deepEqual(pixel(buffer, 10, 1210), [0, 0, 255, 255]);
    assert.equal(await page.evaluate(() => window.scrollY), 0);
  } finally { await browser.close(); }
});

test('Lazy Load無効ではスクロールによる画像読込を開始しない', async () => {
  const browser = await chromium.launch();
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
  const browser = await chromium.launch();
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
      const browser = await chromium.launch();
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
  const browser = await chromium.launch();
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
  const browser = await chromium.launch();
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
  const browser = await chromium.launch();
  try {
    const { page } = await fixtureContext(browser, 'csp', { headers: { 'content-security-policy': "default-src 'none'; style-src 'self'; script-src 'none'" } });
    const buffer = await capturePage(page, fixtureSettings);
    assert.deepEqual(pixel(buffer, 10, 10), [0, 0, 255, 255]);
    assert.deepEqual(pixel(buffer, 10, 50), [255, 0, 255, 255]);
    assert.equal(await page.locator('#marker').evaluate(node => getComputedStyle(node).animationName), 'none');
    assert.equal(await page.evaluate(() => document.body.dataset.inlineScript), undefined);
  } finally { await browser.close(); }
});

test('Basic認証の成功・失敗と別Originの401への資格情報非送信を確認する', async () => {
  const { createServer } = await import('node:http');
  const { once } = await import('node:events');
  const username = 'odvr-fixture-user', password = 'odvr-fixture-password';
  const expected = `Basic ${Buffer.from(`${username}:${password}`).toString('base64')}`;
  const seen = [[], []];
  const servers = seen.map(requests => createServer((request, response) => {
    requests.push(request.headers.authorization ?? null);
    if (request.headers.authorization !== expected) {
      response.writeHead(401, { 'WWW-Authenticate': 'Basic realm="ODVR fixture"' });
      response.end('Unauthorized');
    } else {
      response.writeHead(200, { 'Content-Type': 'text/html' });
      response.end('<style>body{margin:0;background:blue}</style>');
    }
  }));
  const previous = [process.env.ODVR_HTTP_AUTH_USER, process.env.ODVR_HTTP_AUTH_PASSWORD];
  let browser;
  try {
    for (const server of servers) { server.listen(0, '127.0.0.1'); await once(server, 'listening'); }
    const origins = servers.map(server => `http://127.0.0.1:${server.address().port}`);
    process.env.ODVR_HTTP_AUTH_USER = username;
    process.env.ODVR_HTTP_AUTH_PASSWORD = password;
    browser = await chromium.launch();
    const context = await createContext(browser, DEFAULT_DEVICES[0], origins[0]);
    const page = await context.newPage();
    assert.equal((await page.goto(origins[0])).status(), 200);
    assert.ok(seen[0].includes(expected));
    assert.deepEqual(pixel(await capturePage(page, fixtureSettings), 10, 10), [0, 0, 255, 255]);
    // 認証成功後、同一Contextで別ポートのOriginへ遷移して401に挑戦させる。
    try { assert.equal((await page.goto(origins[1])).status(), 401); }
    catch (error) { assert.match(error.message, /ERR_INVALID_AUTH_CREDENTIALS/); }
    assert.ok(seen[1].length > 0);
    assert.ok(seen[1].every(value => value === null));
    await context.close();
    process.env.ODVR_HTTP_AUTH_PASSWORD = 'incorrect-fixture-password';
    const failedContext = await createContext(browser, DEFAULT_DEVICES[0], origins[0]);
    const failedPage = await failedContext.newPage();
    const count = seen[0].length;
    try { assert.equal((await failedPage.goto(origins[0])).status(), 401); }
    catch (error) { assert.match(error.message, /ERR_INVALID_AUTH_CREDENTIALS/); }
    const wrong = `Basic ${Buffer.from(`${username}:incorrect-fixture-password`).toString('base64')}`;
    assert.ok(seen[0].slice(count).includes(wrong));
    await failedContext.close();
  } finally {
    for (const [index, key] of ['ODVR_HTTP_AUTH_USER', 'ODVR_HTTP_AUTH_PASSWORD'].entries()) {
      if (previous[index] === undefined) delete process.env[key]; else process.env[key] = previous[index];
    }
    if (browser) await browser.close();
    for (const server of servers) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
  }
});
